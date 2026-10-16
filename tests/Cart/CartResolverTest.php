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
 * Ein echter `Contao\FrontendUser` lässt sich ohne laufendes Framework nicht
 * über `new` bauen — der Konstruktor ist protected, wie bei jedem
 * Contao-Singleton (`getInstance()`). Per Reflection
 * `newInstanceWithoutConstructor()` geht es aber, ganz ohne Container und
 * ohne Datenbank (geprüft beim Umsetzen dieses Tasks). Der Test nimmt deshalb
 * die echte Klasse statt eines anonymen Doubles — das prüft auch den
 * `instanceof FrontendUser`-Zweig in CartResolver wirklich.
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
        // Kein leerer Datensatz je Besucher: der entsteht erst beim ersten
        // Hinzufügen (Spezifikation 12).
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

        // Derselbe Token, anderer Shop: kein Warenkorb — nicht etwa der von
        // Shop 3. Das ist die Zusicherung, die C-3b trägt.
        self::assertNull($this->resolver(0)->resolve($request, 4));
    }

    public function testDerWarenkorbDesMitgliedsSchlaegtDasCookie(): void
    {
        // Angemeldet zählt das Konto, nicht das Gerät.
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
        // Das Konto gilt für alle Shops, der Warenkorb nicht: ein Shop ist
        // eine rechnungsstellende Einheit (Entscheidung 2026-09-21).
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
     * Baut einen echten FrontendUser ohne Contao-Framework: der Konstruktor
     * ist protected (Singleton-Pattern), newInstanceWithoutConstructor()
     * umgeht ihn, ohne Container oder Datenbank zu brauchen. Der Benutzername
     * ist nur gesetzt, weil UsernamePasswordToken ihn beim Bau abfragt
     * (getUserIdentifier() wirft sonst).
     */
    private function frontendUser(int $memberId): FrontendUser
    {
        $user = (new ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = $memberId;
        $user->username = 'member' . $memberId;

        return $user;
    }
}
