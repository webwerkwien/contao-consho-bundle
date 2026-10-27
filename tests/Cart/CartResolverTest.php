<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cart;

use Contao\FrontendUser;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webwerkwien\ContaoConshoBundle\Cart\CartResolver;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;

/**
 * A real `Contao\FrontendUser` cannot be built with `new` without a running
 * framework — the constructor is protected, as with every
 * Contao singleton (`getInstance()`). Via reflection
 * `newInstanceWithoutConstructor()` it works, though, entirely without a
 * container and without a database. The test therefore
 * takes the real class instead of an anonymous double — that also really
 * exercises the `instanceof FrontendUser` branch in CartResolver.
 */
class CartResolverTest extends TestCase
{
    use CartSchema;

    protected function setUp(): void
    {
        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCartTables();
    }

    public function testOhneCookieUndOhneMitgliedGibtEsKeinenWarenkorb(): void
    {
        // No empty record per visitor: it is created only on the first
        // add (specification 12).
        self::assertNull($this->resolver(0)->resolve(new Request(), 3));
    }

    public function testMitCookieWirdDerGastWarenkorbGefunden(): void
    {
        $repository = new DbalCartRepository($this->cartConnection);
        $id = $repository->create(CartToken::hash('token'), 0, 3, 1_000);

        $request = new Request();
        $request->cookies->set(CartToken::NAME, str_pad('token', 43, 'x'));

        $withWrongToken = $this->resolver(0)->resolve($request, 3);
        self::assertNull($withWrongToken, 'ein anderer Token ist ein anderer Warenkorb');

        $token = CartToken::generate();
        $this->cartConnection->update('tl_consho_cart', ['tokenHash' => CartToken::hash($token)], ['id' => $id]);
        $request->cookies->set(CartToken::NAME, $token);

        $withRightToken = $this->resolver(0)->resolve($request, 3);
        self::assertSame($id, $withRightToken?->id);

        // The same token, another shop: no cart — and certainly not the one of
        // shop 3. That is the assertion C-3b rests on.
        self::assertNull($this->resolver(0)->resolve($request, 4));
    }

    public function testDerWarenkorbDesMitgliedsSchlaegtDasCookie(): void
    {
        // Logged in, the account counts, not the device.
        $repository = new DbalCartRepository($this->cartConnection);
        $token = CartToken::generate();
        $repository->create(CartToken::hash($token), 0, 3, 1_000);
        $memberCart = $repository->create(null, 5, 3, 1_000);

        $request = new Request();
        $request->cookies->set(CartToken::NAME, $token);

        self::assertSame($memberCart, $this->resolver(5)->resolve($request, 3)?->id);
    }

    public function testEinMitgliedBeiZweiShopsBekommtJeShopSeinenWarenkorb(): void
    {
        // The account applies to all shops, the cart does not: a shop is
        // an invoicing entity.
        $repository = new DbalCartRepository($this->cartConnection);
        $inShopThree = $repository->create(null, 5, 3, 1_000);
        $inShopFour = $repository->create(null, 5, 4, 1_000);

        self::assertSame($inShopThree, $this->resolver(5)->resolve(new Request(), 3)?->id);
        self::assertSame($inShopFour, $this->resolver(5)->resolve(new Request(), 4)?->id);
    }

    private function resolver(int $memberId): CartResolver
    {
        $storage = new TokenStorage();

        if ($memberId > 0) {
            $storage->setToken(new UsernamePasswordToken($this->frontendUser($memberId), 'contao_frontend', []));
        }

        return new CartResolver(new DbalCartRepository($this->cartConnection), $storage);
    }

    /**
     * Builds a real FrontendUser without the Contao framework: the constructor
     * is protected (singleton pattern), newInstanceWithoutConstructor()
     * bypasses it without needing a container or a database. The username
     * is set only because UsernamePasswordToken asks for it on construction
     * (getUserIdentifier() throws otherwise).
     */
    private function frontendUser(int $memberId): FrontendUser
    {
        $user = (new ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = $memberId;
        $user->username = 'member' . $memberId;

        return $user;
    }
}
