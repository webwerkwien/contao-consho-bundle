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
        // 7.1, Schritt 5: gleicher Artikel → Mengen addiert.
        $member = $this->repository->create(null, 5, 3, 1_000);
        $this->repository->addItem($member, 7, '2.0000', 1_000);

        $guest = $this->repository->create(CartToken::hash('t'), 0, 3, 1_000);
        $this->repository->addItem($guest, 7, '3.0000', 1_000);
        $this->repository->addItem($guest, 9, '1.0000', 1_000);

        $this->repository->takeOver($this->guestCart('t', 3), 5, 2_000);

        $lines = $this->cartConnection->fetchAllKeyValue('SELECT product, quantity FROM tl_consho_cart_item WHERE pid = ? ORDER BY product', [$member]);

        // Numerisch: SQLite gibt aus einer NUMERIC-Spalte 5 zurück, nicht
        // '5.0000' (siehe Befunde im Kopf des Plans).
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
        // Gastkorb bei Shop 3, Mitgliedskorb bei Shop 4: Sie haben
        // miteinander nichts zu tun — ein Shop ist eine rechnungsstellende
        // Einheit. Vorher suchte takeOver() den Mitgliedskorb ohne Shop und
        // schob die Position über die Grenze.
        $inShopFour = $this->repository->create(null, 5, 4, 1_000);
        $this->repository->addItem($inShopFour, 9, '2.0000', 1_000);

        $guest = $this->repository->create(CartToken::hash('t'), 0, 3, 1_000);
        $this->repository->addItem($guest, 7, '1.0000', 1_000);

        $this->repository->takeOver($this->guestCart('t', 3), 5, 2_000);

        // Zwei Warenkörbe danach, jeder mit seiner eigenen Position.
        self::assertSame(2, (int) $this->cartConnection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart WHERE member = 5'));
        self::assertSame([7], $this->productsOf($guest), 'der Gastkorb behält seine Position und seinen Shop');
        self::assertSame([9], $this->productsOf($inShopFour), 'in den fremden Shop darf nichts gewandert sein');
        self::assertSame(3, (int) $this->cartConnection->fetchOne('SELECT shop FROM tl_consho_cart WHERE id = ?', [$guest]));
    }

    public function testBeimAnmeldenWandernAlleWarenkoerbeDesTokensMit(): void
    {
        // Befund beim Aufsetzen von C-3b: Die Anmeldung gehört zu der Seite,
        // die das Formular trug — sie kann gar nicht sagen, welcher Shop
        // gemeint ist. Übernähme sie nur einen Korb, bliebe jeder andere als
        // Waise zurück: Sein Token wird nie wieder aufgelöst, der Cron löscht
        // ihn nach dreißig Tagen, ohne dass der Besucher ihn je wiedersieht.
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
        // Durch onLoginSuccess() hindurch, nicht am Listener vorbei. Der Test
        // darüber baut die Schleife selbst nach und prüft damit das
        // Repository — die Schleife im Listener bewacht er nicht. Ersetzt man
        // sie durch `$guests[0]`, bleibt er grün (gefunden im Abschlussreview
        // von C-3b). Dieser hier wird dann rot.
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
        // Nach dem Kauf ist der Datensatz kein Warenkorb mehr, sondern der
        // Beleg, dass dieser Token schon gekauft hat — C-7 braucht ihn noch.
        // Fiele der state-Filter in findAllByToken() weg, schriebe die
        // Anmeldung ihn zum Mitgliedskorb um und der Beleg wäre verloren.
        // Heute kann das niemand auslösen, weil nichts `converted` schreibt;
        // ab C-7 schon. Der Filter steht im Code — hier bewacht ihn etwas.
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
     * Ein echter LoginSuccessEvent, wie ihn AuthenticatorManager bei einer
     * Formularanmeldung auslöst: mit der Anfrage, die das Cookie trägt, und
     * dem Benutzer aus dem Passport — genau das, was der Listener liest.
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
        // Unter PHP-FPM stirbt der Dienst mit der Anfrage. Unter einem
        // Arbeiter-Laufzeitsystem (FrankenPHP, RoadRunner) nicht — und ein
        // Merker, den eine Anmeldung gesetzt hat, deren Antwort-Listener nie
        // lief, löschte dann das Warenkorb-Cookie des NÄCHSTEN Besuchers.
        // Symfony setzt die Dienste dort zurück; das hier ist der Haken dafür.
        $listener = new CartTakeoverListener($this->repository);

        // Über class_implements, nicht assertInstanceOf: Letzteres wäre für
        // eine Klasse, die die Schnittstelle deklariert, immer wahr — ein
        // Test, der nie rot werden kann. Hier zählt, dass die Schnittstelle
        // überhaupt dasteht: ohne sie ruft Symfony reset() nie auf.
        self::assertContains(ResetInterface::class, class_implements($listener));

        $reflection = new \ReflectionProperty($listener, 'clearCookie');
        $reflection->setValue($listener, true);

        $listener->reset();

        self::assertFalse($reflection->getValue($listener));
    }
}
