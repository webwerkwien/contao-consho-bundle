<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Cancellation\SubscriptionContact;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;
use Webwerkwien\ContaoConshoBundle\Customer\MySubscriptionsFlow;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseConfirmation;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseScheduled;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalContext;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalOffer;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Token\DbalTokenRepository;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;

/**
 * "My subscriptions" (Abo-7, P60): only one's own subscriptions, and every
 * step checks that again — an id from the URL is never enough.
 */
final class MySubscriptionsFlowTest extends TestCase
{
    private const HERE = 'https://shop.test/abos.html';

    private const ORDER_PAGE = 'https://shop.test/bestellung.html';

    private const NOW = 1_800_000_000;

    private const DAY = 86_400;

    private const CART = 'https://shop.test/warenkorb.html';

    private Connection $connection;

    private UriSigner $signer;

    /** Abo-7b: the context the page would hand in; null = none. */
    private RenewalContext|null $context = null;

    /** @var list<PauseScheduled> the pauses confirmed by mail */
    private array $confirmed = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_subscription_pause', 'tl_consho_cancellation', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_order_token', 'tl_consho_cart', 'tl_consho_cart_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 70, 'shop' => 1, 'member' => 5, 'orderNumber' => 'WEB-00070', 'email' => 'erika@example.org', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => 70, 'productName' => 'E-Book Kräuterkunde']);

        $base = ['shop' => 1, 'member' => 5, 'firstOrder' => 70, 'orderItem' => 3, 'ruleTitle' => 'Monatsabo', 'state' => 'active', 'phase' => 'minimum',
            'renewal' => 'indefinite', 'termLength' => 1, 'termUnit' => 'year', 'noticeDays' => 30,
            'startedAt' => self::NOW - 100 * self::DAY, 'minimumEndsAt' => self::NOW + 265 * self::DAY, 'nextBillingAt' => self::NOW + 20 * self::DAY];

        // 1 running, 2 cancelled, 3 ended, 4 another customer's, 5 another shop.
        $this->subscription(1, $base);
        $this->subscription(2, ['endsAt' => self::NOW + 10 * self::DAY, 'startedAt' => self::NOW - 200 * self::DAY] + $base);
        $this->subscription(3, ['state' => 'ended', 'endsAt' => self::NOW - self::DAY, 'startedAt' => self::NOW - 300 * self::DAY] + $base);
        $this->subscription(4, ['member' => 6] + $base);
        $this->subscription(5, ['shop' => 2] + $base);

        $this->signer = new UriSigner('test-secret', CancellationForm::HASH_PARAM, CancellationForm::EXPIRES_PARAM);
    }

    /**
     * Pause a subscription (P101): the link only where a pause can be chosen;
     * the page names the start and the lengths with their end.
     */
    public function testPausierenNurWoEsGehtUndMitDenLaengen(): void
    {
        $this->pausable(1);

        $view = $this->view(Request::create(self::HERE));
        self::assertSame([self::HERE.'?consho_pause_subscription=1', '', ''], array_column($view['subscriptions'], 'pauseUrl'));

        $page = $this->view(Request::create(self::HERE.'?consho_pause_subscription=1'))['pause'];
        self::assertIsArray($page);
        self::assertSame(1, $page['subscription']);
        self::assertSame([1, 2], array_column($page['options'], 'months'));
        self::assertNotSame($page['options'][0]['until'], $page['options'][1]['until']);

        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=pauseUnavailable'], $this->handle(Request::create(self::HERE.'?consho_pause_subscription=2'), 5), 'gekündigt');
        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=notFound'], $this->handle(Request::create(self::HERE.'?consho_pause_subscription=4'), 5), 'fremd');
    }

    /**
     * P101, P110: confirmed → announced, mail, notice; P107: take back.
     */
    public function testPauseBestaetigenUndZuruecknehmen(): void
    {
        $this->pausable(1);

        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=paused'], $this->handle($this->pausePost(1, 2), 5));
        self::assertCount(1, $this->confirmed);
        self::assertSame(['WEB-00001-1', 2], [$this->confirmed[0]->subscriptionNumber, $this->confirmed[0]->months]);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT pausedMonths FROM tl_consho_subscription WHERE id = 1'));

        $line = $this->view(Request::create(self::HERE))['subscriptions'][0];
        self::assertNotSame('', $line['pauseAnnounced']);
        self::assertSame('', $line['pauseUrl'], 'eine Pause zur Zeit');

        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=pauseWithdrawn'], $this->handle(Request::create(self::HERE, 'POST', ['consho_withdraw_pause' => '1']), 5));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT pausedMonths FROM tl_consho_subscription WHERE id = 1'));
        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=pauseUnavailable'], $this->handle(Request::create(self::HERE, 'POST', ['consho_withdraw_pause' => '1']), 5), 'nichts mehr zurückzunehmen');
    }

    public function testPauseNurFuerDasEigeneAboUndNurAngeboteneLaengen(): void
    {
        $this->pausable(1);
        $this->pausable(4);

        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=notFound'], $this->handle($this->pausePost(4, 1), 5));
        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=pauseUnavailable'], $this->handle($this->pausePost(1, 5), 5), 'mehr als das Kontingent');
        self::assertSame([], $this->confirmed);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_subscription_pause'));
    }

    public function testPausiertZeigtBisWann(): void
    {
        $this->connection->update('tl_consho_subscription', ['state' => 'paused', 'pauseFrom' => self::NOW - self::DAY, 'pauseUntil' => self::NOW + 30 * self::DAY], ['id' => 1]);

        $line = $this->view(Request::create(self::HERE))['subscriptions'][0];

        self::assertSame('paused', $line['state']);
        self::assertNotSame('', $line['pauseUntil']);
        self::assertSame('', $line['pauseAnnounced'], 'läuft schon: nicht mehr zurücknehmbar');
    }

    private function pausable(int $id): void
    {
        $this->connection->update('tl_consho_subscription', [
            'billingInterval' => 1, 'pauseMonths' => 2,
            'periodFrom' => self::NOW - 10 * self::DAY, 'periodUntil' => self::NOW + 20 * self::DAY,
        ], ['id' => $id]);
    }

    private function pausePost(int $subscriptionId, int $months): Request
    {
        return Request::create(self::HERE, 'POST', ['consho_pause_action' => 'confirm', 'consho_pause_subscription' => (string) $subscriptionId, 'consho_pause_months' => (string) $months]);
    }

    public function testAbgemeldetNichts(): void
    {
        self::assertSame(['view' => null], $this->handle(Request::create(self::HERE), 0));
    }

    public function testNurDieEigenenAbosInDiesemShop(): void
    {
        $view = $this->view(Request::create(self::HERE));

        self::assertSame(['WEB-00001-1', 'WEB-00002-1', 'WEB-00003-1'], array_column($view['subscriptions'], 'number'));
        self::assertSame(['active', 'ending', 'ended'], array_column($view['subscriptions'], 'state'));

        [$running, $ending, $ended] = $view['subscriptions'];
        self::assertSame('E-Book Kräuterkunde', $running['product']);
        self::assertSame('Monatsabo', $running['model']);
        self::assertNotSame('', $running['nextBilling']);
        self::assertSame(self::HERE.'?consho_cancel_subscription=1', $running['cancelUrl']);
        self::assertSame(self::HERE.'?consho_open_subscription=1', $running['orderUrl']);

        // The end is before the next billing: no more.
        self::assertSame('', $ending['nextBilling']);
        self::assertNotSame('', $ending['endsAt']);

        self::assertSame('', $ended['nextBilling']);
        self::assertSame('', $ended['cancelUrl'], 'ein beendetes Abo kündigt man nicht');
    }

    public function testDieBestellseiteDesKaufsMitToken(): void
    {
        $result = $this->handle(Request::create(self::HERE.'?consho_open_subscription=1'), 5);

        self::assertStringStartsWith(self::ORDER_PAGE.'?consho_order=', $result['redirect'] ?? '');
        self::assertSame(70, (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_order_token'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fremde(): iterable
    {
        yield 'Bestellseite, fremdes Abo' => ['?consho_open_subscription=4'];
        yield 'Bestellseite, anderer Shop' => ['?consho_open_subscription=5'];
        yield 'Kündigen, fremdes Abo' => ['?consho_cancel_subscription=4'];
        yield 'Kündigen, gibt es nicht' => ['?consho_cancel_subscription=99'];
    }

    /**
     * @dataProvider fremde
     */
    public function testEinFremdesAboNicht(string $query): void
    {
        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=notFound'], $this->handle(Request::create(self::HERE.$query), 5));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order_token'));
    }

    public function testKuendigenZeigtDieBestaetigungsseiteOhneEMailAbfrage(): void
    {
        $view = $this->view(Request::create(self::HERE.'?consho_cancel_subscription=1'));

        self::assertTrue($view['form']);
        self::assertSame(1, $view['subscription']);
        self::assertSame('WEB-00001-1 — E-Book Kräuterkunde', $view['contract']);

        // Abo-7: the earliest possible end before "cancel now" —
        // notice period kept, so the end of the minimum term.
        self::assertSame(CancellationForm::moment(self::NOW + 265 * self::DAY, 'de'), $view['earliest']);

        // Already cancelled: none any more.
        self::assertSame('', $this->view(Request::create(self::HERE.'?consho_cancel_subscription=2'))['earliest']);
    }

    /**
     * The name from the account is pre-filled, editable — an error keeps what
     * was typed.
     */
    public function testDerNameAusDemKontoIstVorausgefuellt(): void
    {
        $shown = $this->handle(Request::create(self::HERE.'?consho_cancel_subscription=1'), 5, ' Erika Muster ');
        self::assertSame('Erika Muster', $shown['view']['input']['name'] ?? null);
        self::assertSame('ordinary', $shown['view']['input']['kind'] ?? null);

        $retyped = $this->handle($this->confirm(1, ['consho_cancellation_name' => 'E. Muster', 'consho_cancellation_kind' => 'extraordinary']), 5, 'Erika Muster');
        self::assertSame('E. Muster', $retyped['view']['input']['name'] ?? null, 'nach einem Fehler das Getippte');
    }

    public function testDieErgebnisseiteGilt90Tage(): void
    {
        $location = $this->handle($this->confirm(1, ['consho_cancellation_name' => 'E']), 5)['redirect'] ?? '';

        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame((string) (self::NOW + 90 * self::DAY), $query['consho_expires'] ?? null);
    }

    /**
     * After expiry the page says so, instead of silently showing the
     * list.
     */
    public function testNachAblaufEinHinweis(): void
    {
        $this->handle($this->confirm(1, ['consho_cancellation_name' => 'E']), 5);
        $expired = $this->signer->sign(self::HERE.'?consho_cancellation=1', time() - 60);

        $view = $this->view(Request::create($expired));

        self::assertNull($view['result']);
        self::assertSame('expired', $view['notice']);
    }

    public function testJetztKuendigenSpeichertUndLeitetZurErgebnisseite(): void
    {
        $result = $this->handle($this->confirm(1, ['consho_cancellation_name' => 'Erika Muster']), 5);

        $location = $result['redirect'] ?? '';
        self::assertTrue($this->signer->check($location));
        self::assertSame(['account', 'effective'], array_values($this->connection->fetchAssociative('SELECT source, state FROM tl_consho_cancellation WHERE pid = 1') ?: []));

        $shown = $this->view(Request::create($location));
        self::assertSame('WEB-00001-1-K1', $shown['result']['number'] ?? null);
    }

    public function testEinFremdesAboLaesstSichNichtKuendigen(): void
    {
        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=notFound'], $this->handle($this->confirm(4, ['consho_cancellation_name' => 'E']), 5));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
    }

    public function testFehlerBleibenAufDerBestaetigungsseite(): void
    {
        $view = $this->view($this->confirm(1, ['consho_cancellation_name' => '']));

        self::assertTrue($view['form']);
        self::assertSame('missing', $view['error']);
        self::assertSame(1, $view['subscription']);
    }

    public function testNurBekannteHinweise(): void
    {
        self::assertSame('', $this->view(Request::create(self::HERE.'?consho_my_subscriptions=<b>frei</b>'))['notice']);
        self::assertSame('notFound', $this->view(Request::create(self::HERE.'?consho_my_subscriptions=notFound'))['notice']);
    }

    public function testLizenzVerlaengernNurWoEsSieGibt(): void
    {
        // Abo-7b (P66, P67): the double says 2 (cancelled) and 3 (ended) can
        // be renewed, only 2 is offered — 3 lacks the price.
        $this->context = $this->renewalContext();

        $lines = $this->view(Request::create(self::HERE))['subscriptions'];

        self::assertSame([null, '1,20 € / Monat', null], array_column($lines, 'renew'));
        self::assertSame([1, 2, 3], array_column($lines, 'id'));
    }

    public function testOhneZusammenhangKeinLink(): void
    {
        // No currency or no cart page: the controller passes null.
        self::assertSame([null, null, null], array_column($this->view(Request::create(self::HERE))['subscriptions'], 'renew'));
    }

    public function testDerKlickLegtDieZeileInDenWarenkorbDesMitglieds(): void
    {
        $this->context = $this->renewalContext();

        self::assertSame(['redirect' => self::CART], $this->handle($this->renew(2), 5));
        self::assertSame(['redirect' => self::CART], $this->handle($this->renew(2), 5), 'ein zweiter Klick');

        $cart = $this->connection->fetchAssociative('SELECT id, member, shop, state FROM tl_consho_cart');
        self::assertIsArray($cart);
        self::assertSame([5, 1, 'open'], [(int) $cart['member'], (int) $cart['shop'], $cart['state']]);

        $lines = $this->connection->fetchAllAssociative('SELECT product, quantity, subscriptionRule, renews FROM tl_consho_cart_item');
        self::assertCount(1, $lines, 'eine Zeile, nicht zwei');
        self::assertSame([7, 2.0, 5, 2], [(int) $lines[0]['product'], (float) $lines[0]['quantity'], (int) $lines[0]['subscriptionRule'], (int) $lines[0]['renews']]);
    }

    public function testDerVorhandeneWarenkorbWirdGenommen(): void
    {
        $this->context = $this->renewalContext();
        $carts = new DbalCartRepository($this->connection);
        $cartId = $carts->create(null, 5, 1, self::NOW - 60);
        $carts->addItem($cartId, 9, '1.0000', self::NOW - 60);

        $this->handle($this->renew(2), 5);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart'));
        self::assertSame([0, 2], array_map(static fn ($item): int => $item->renews, $carts->itemsOf($cartId)));
    }

    public function testNichtVerlaengerbarOderFremdKeineZeile(): void
    {
        $this->context = $this->renewalContext();
        $unavailable = ['redirect' => self::HERE.'?consho_my_subscriptions=renewalUnavailable'];

        self::assertSame($unavailable, $this->handle($this->renew(3), 5), 'ohne Preis');
        self::assertSame($unavailable, $this->handle($this->renew(1), 5), 'läuft weiter');
        self::assertSame(['redirect' => self::HERE.'?consho_my_subscriptions=notFound'], $this->handle($this->renew(4), 5), 'fremdes Abo');

        $this->context = null;
        self::assertSame($unavailable, $this->handle($this->renew(2), 5), 'ohne Zusammenhang');

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cart_item'));
    }

    public function testEineVerlaengerungBeginntErst(): void
    {
        // Begins at the end of the old subscription — "running" would be too early.
        $this->connection->update('tl_consho_subscription', ['startedAt' => self::NOW + 10 * self::DAY], ['id' => 1]);

        $line = $this->view(Request::create(self::HERE))['subscriptions'][0];

        self::assertSame('upcoming', $line['state']);
        self::assertSame(CancellationForm::day(self::NOW + 10 * self::DAY, 'de'), $line['startsAt']);

        // If it is already paused, it says so.
        $this->connection->update('tl_consho_subscription', ['state' => 'suspended'], ['id' => 1]);
        self::assertSame('suspended', $this->view(Request::create(self::HERE))['subscriptions'][0]['state']);
    }

    public function testDerHinweisAufDenNachfolger(): void
    {
        $this->connection->update('tl_consho_subscription', ['renewedBy' => 1], ['id' => 3]);

        self::assertSame(['', '', 'WEB-00001-1'], array_column($this->view(Request::create(self::HERE))['subscriptions'], 'renewedBy'));
    }

    private function renew(int $subscriptionId): Request
    {
        return Request::create(self::HERE, 'POST', ['consho_renew_subscription' => (string) $subscriptionId]);
    }

    private function renewalContext(): RenewalContext
    {
        return new RenewalContext('EUR', 'AT', [3], false, self::CART);
    }

    /**
     * @param array<string, string> $post
     */
    private function confirm(int $subscriptionId, array $post): Request
    {
        return Request::create(self::HERE, 'POST', ['consho_cancellation_action' => 'confirm', 'consho_cancellation_subscription' => (string) $subscriptionId] + $post);
    }

    /**
     * @return array<string, mixed>
     */
    private function view(Request $request): array
    {
        $result = $this->handle($request, 5);

        self::assertArrayHasKey('view', $result);
        self::assertIsArray($result['view']);

        return $result['view'];
    }

    /**
     * @return array{redirect: string}|array{view: array<string, mixed>|null}
     */
    private function handle(Request $request, int $memberId, string $memberName = ''): array
    {
        $lock = new class($this->connection) implements SubscriptionLockInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function lock(int $subscriptionId): array|null
            {
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);

                return false === $row ? null : $row;
            }
        };

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('cancellationReceived')->willReturn(true);

        $calendar = new SubscriptionCalendar();
        $confirmation = $this->createMock(PauseConfirmation::class);
        $confirmation->method('send')->willReturnCallback(function (PauseScheduled $pause): bool {
            $this->confirmed[] = $pause;

            return true;
        });
        $recorder = new CancellationRecorder($this->connection, $lock, new CancellationTerm($calendar), $mail, $this->createStub(OrderPageLink::class), new SubscriptionContact($this->connection));

        // Abo-7b: SubscriptionRenewal has its own test; here only who is
        // asked what. 2 and 3 are renewable, only 2 has a price.
        $renewal = $this->createStub(SubscriptionRenewal::class);
        $renewal->method('renewable')->willReturnCallback(static fn (array $row): bool => \in_array((int) $row['id'], [2, 3], true));
        $renewal->method('offer')->willReturnCallback(static fn (array $row): RenewalOffer|null => 2 === (int) $row['id'] ? new RenewalOffer(2, 7, 5, '2.0000', '1,20 € / Monat') : null);

        $flow = new MySubscriptionsFlow(
            $this->connection,
            new CancellationForm($this->connection, $recorder, $calendar, $this->signer),
            new TokenGenerator(new DbalTokenRepository($this->connection)),
            new CancellationTerm($calendar),
            $renewal,
            new DbalCartRepository($this->connection),
            new SubscriptionPause($this->connection, $lock, $calendar),
            $confirmation,
        );

        return $flow->handle($request, 1, self::ORDER_PAGE, $memberId, 'de', self::HERE, self::NOW, $memberName, $this->context);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function subscription(int $id, array $values): void
    {
        $this->connection->insert('tl_consho_subscription', $values + ['id' => $id, 'subscriptionNumber' => \sprintf('WEB-%05d-1', $id)]);
    }
}
