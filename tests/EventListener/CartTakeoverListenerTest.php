<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener;

use Contao\FrontendUser;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Cart\Cart;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\EventListener\CartTakeoverListener;
use Webwerkwien\ContaoConshoBundle\Tests\Cart\CartSchema;

class CartTakeoverListenerTest extends TestCase
{
    use CartSchema;

    private DbalCartRepository $repository;

    protected function setUp(): void
    {
        $this->cartConnection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCartTables();
        $this->repository = new DbalCartRepository($this->cartConnection);
    }

    public function testOhneMitgliedsWarenkorbWirdDerGastWarenkorbUebernommen(): void
    {
        $guest = $this->repository->create(CartToken::hash('t'), 0, 3, 1_000);
        $this->repository->addItem($guest, 7, '1.0000', 1_000);

        $this->repository->takeOver($this->guestCart('t', 3), 5, 2_000);

        $row = $this->cartConnection->fetchAssociative('SELECT member, tokenHash FROM tl_consho_cart WHERE id = ?', [$guest]);

        self::assertSame(5, (int) $row['member']);
        self::assertNull($row['tokenHash'], 'der Hash muss weg, sonst führen zwei Wege zum selben Warenkorb');
    }

    public function testMitMitgliedsWarenkorbWerdenDieMengenAddiert(): void
    {
        // 7.1, step 5: same item → quantities added.
        $member = $this->repository->create(null, 5, 3, 1_000);
        $this->repository->addItem($member, 7, '2.0000', 1_000);

        $guest = $this->repository->create(CartToken::hash('t'), 0, 3, 1_000);
        $this->repository->addItem($guest, 7, '3.0000', 1_000);
        $this->repository->addItem($guest, 9, '1.0000', 1_000);

        $this->repository->takeOver($this->guestCart('t', 3), 5, 2_000);

        $lines = $this->cartConnection->fetchAllKeyValue('SELECT product, quantity FROM tl_consho_cart_item WHERE pid = ? ORDER BY product', [$member]);

        // Numeric: SQLite returns 5 from a NUMERIC column, not '5.0000'.
        self::assertSame([7 => 5.0, 9 => 1.0], array_map(floatval(...), array_combine(array_map(intval(...), array_keys($lines)), $lines)));
        self::assertSame(0, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart WHERE id = ?', [$guest]));
    }

    public function testEinLeererGastWarenkorbVerschwindetSpurlos(): void
    {
        $member = $this->repository->create(null, 5, 3, 1_000);
        $this->repository->create(CartToken::hash('t'), 0, 3, 1_000);

        $this->repository->takeOver($this->guestCart('t', 3), 5, 2_000);

        self::assertSame(1, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
        self::assertSame($member, (int) $this->cartConnection->fetchOne('SELECT id FROM tl_consho_cart'));
    }

    public function testDieUebernahmeFuehrtNichtsUeberEineShopGrenzeZusammen(): void
    {
        // Guest cart at shop 3, member cart at shop 4: they have nothing to
        // do with each other — a shop is an invoicing unit. Previously
        // takeOver() looked for the member cart without a shop and pushed the
        // item across the boundary.
        $inShopFour = $this->repository->create(null, 5, 4, 1_000);
        $this->repository->addItem($inShopFour, 9, '2.0000', 1_000);

        $guest = $this->repository->create(CartToken::hash('t'), 0, 3, 1_000);
        $this->repository->addItem($guest, 7, '1.0000', 1_000);

        $this->repository->takeOver($this->guestCart('t', 3), 5, 2_000);

        // Two carts afterwards, each with its own item.
        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart WHERE member = 5'));
        self::assertSame([7], $this->productsOf($guest), 'der Gastkorb behält seine Position und seinen Shop');
        self::assertSame([9], $this->productsOf($inShopFour), 'in den fremden Shop darf nichts gewandert sein');
        self::assertSame(3, (int) $this->cartConnection->fetchOne('SELECT shop FROM tl_consho_cart WHERE id = ?', [$guest]));
    }

    public function testBeimAnmeldenWandernAlleWarenkoerbeDesTokensMit(): void
    {
        // The login belongs to the page that carried the form — it cannot tell
        // at all which shop is meant. If it took over only one cart, every other
        // one would be left behind as an orphan: its token is never resolved
        // again, and the cron deletes it after thirty days without the visitor
        // ever seeing it again.
        $inShopThree = $this->repository->create(CartToken::hash('t'), 0, 3, 1_000);
        $inShopFour = $this->repository->create(CartToken::hash('t'), 0, 4, 1_000);
        $this->repository->addItem($inShopThree, 7, '1.0000', 1_000);
        $this->repository->addItem($inShopFour, 9, '1.0000', 1_000);

        foreach ($this->repository->findAllByToken('t') as $guest) {
            $this->repository->takeOver($guest, 5, 2_000);
        }

        self::assertSame(
            [3, 4],
            array_map(intval(...), $this->cartConnection->fetchFirstColumn(
                'SELECT shop FROM tl_consho_cart WHERE member = 5 ORDER BY shop',
            )),
            'beide Körbe hängen danach am Mitglied, jeder in seinem Shop',
        );
        self::assertSame(
            0,
            (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart WHERE tokenHash IS NOT NULL'),
            'kein Korb bleibt am Token zurück',
        );
    }

    public function testDieAnmeldungUebernimmtAlleWarenkoerbeDesTokens(): void
    {
        // Through onLoginSuccess(), not past the listener. The test above
        // rebuilds the loop itself and thereby checks the repository — it does
        // not guard the loop in the listener. Replace it with `$guests[0]` and
        // that test stays green. This one then turns red.
        $token = CartToken::generate();
        $inShopThree = $this->repository->create(CartToken::hash($token), 0, 3, 1_000);
        $inShopFour = $this->repository->create(CartToken::hash($token), 0, 4, 1_000);
        $this->repository->addItem($inShopThree, 7, '1.0000', 1_000);
        $this->repository->addItem($inShopFour, 9, '1.0000', 1_000);

        (new CartTakeoverListener($this->repository))->onLoginSuccess($this->loginEvent($token, 5));

        self::assertSame(
            [3, 4],
            array_map(intval(...), $this->cartConnection->fetchFirstColumn(
                'SELECT shop FROM tl_consho_cart WHERE member = 5 ORDER BY shop',
            )),
            'beide Körbe hängen danach am Mitglied, jeder in seinem Shop',
        );
        self::assertSame([7], $this->productsOf($inShopThree));
        self::assertSame([9], $this->productsOf($inShopFour));
    }

    public function testEinUmgewandelterWarenkorbWandertBeimAnmeldenNichtMit(): void
    {
        // After the purchase the record is no longer a cart but the proof that
        // this token has already bought — C-7 still needs it. If the state
        // filter in findAllByToken() were dropped, the login would rewrite it
        // into the member cart and the proof would be lost. Today nobody can
        // trigger that, because nothing writes `converted`; from C-7 on, they
        // can. The filter is in the code — here something guards it.
        $token = CartToken::generate();
        $converted = $this->repository->create(CartToken::hash($token), 0, 3, 1_000);
        $this->cartConnection->update('tl_consho_cart', ['state' => 'converted'], ['id' => $converted]);

        (new CartTakeoverListener($this->repository))->onLoginSuccess($this->loginEvent($token, 5));

        $row = $this->cartConnection->fetchAssociative(
            'SELECT member, tokenHash, state FROM tl_consho_cart WHERE id = ?',
            [$converted],
        );

        self::assertSame(0, (int) $row['member'], 'der Beleg gehört keinem Mitglied');
        self::assertNotNull($row['tokenHash'], 'und behält seinen Hash, sonst findet C-7 ihn nicht mehr');
        self::assertSame('converted', $row['state']);
    }

    /**
     * A real LoginSuccessEvent, as AuthenticatorManager dispatches it on a form
     * login: with the request that carries the cookie, and the user from the
     * passport — exactly what the listener reads.
     */
    private function loginEvent(string $token, int $memberId): LoginSuccessEvent
    {
        $user = (new \ReflectionClass(FrontendUser::class))->newInstanceWithoutConstructor();
        $user->id = $memberId;
        $user->username = 'member'.$memberId;

        $request = new Request();
        $request->cookies->set(CartToken::NAME, $token);

        return new LoginSuccessEvent(
            $this->createMock(AuthenticatorInterface::class),
            new SelfValidatingPassport(new UserBadge($user->username, static fn (): FrontendUser => $user)),
            new UsernamePasswordToken($user, 'contao_frontend', []),
            $request,
            null,
            'contao_frontend',
        );
    }

    /**
     * @return list<int>
     */
    private function productsOf(int $cartId): array
    {
        return array_map(intval(...), $this->cartConnection->fetchFirstColumn(
            'SELECT product FROM tl_consho_cart_item WHERE pid = ? ORDER BY product',
            [$cartId],
        ));
    }

    private function guestCart(string $token, int $shopId): Cart
    {
        $cart = $this->repository->findByToken($token, $shopId);

        self::assertNotNull($cart, 'der Gastwarenkorb muss für den Test auffindbar sein');

        return $cart;
    }

    public function testDerMerkerUeberlebtKeineZweiteAnfrage(): void
    {
        // Under PHP-FPM the service dies with the request. Under a worker
        // runtime (FrankenPHP, RoadRunner) it does not — and a flag set by a
        // login whose response listener never ran would then delete the cart
        // cookie of the NEXT visitor. Symfony resets the services there; this
        // is the hook for it.
        $listener = new CartTakeoverListener($this->repository);

        // Via class_implements, not assertInstanceOf: the latter would always be
        // true for a class that declares the interface — a test that can never
        // turn red. What counts here is that the interface is there at all:
        // without it Symfony never calls reset().
        self::assertContains(ResetInterface::class, class_implements($listener));

        $reflection = new \ReflectionProperty($listener, 'clearCookie');
        $reflection->setValue($listener, true);

        $listener->reset();

        self::assertFalse($reflection->getValue($listener));
    }
}
