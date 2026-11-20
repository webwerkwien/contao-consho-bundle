<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDeclaration;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationKind;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationOutcome;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationResult;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Subscription\PriceOfferAnswered;
use Webwerkwien\ContaoConshoBundle\Subscription\PriceOfferDue;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPriceOfferRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPriceOffers;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-10: whom the run asks, what the mail says, and that an offer whose
 * mail did not leave is taken back (P135).
 */
final class SubscriptionPriceOfferRunTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    /** @var list<int> */
    private array $asked = [];

    /** @var list<string> */
    private array $marks = [];

    /** @var list<array<string, mixed>> */
    private array $mails = [];

    private bool $mailLeaves = true;

    /** @var list<int> offers whose mark as sent throws */
    private array $sentFails = [];

    /** @var array<int, string> */
    private array $voids = [];

    /** @var array<int, PriceOfferDue> */
    private array $lapses = [];

    /** @var array<int, PriceOfferDue> */
    private array $reminders = [];

    private PriceOfferAnswered|null $answered = null;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_subscription_price', 'tl_consho_order', 'tl_consho_order_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'consho_subscriptionsPage' => 77]);
        $this->connection->insert('tl_page', ['id' => 77, 'pid' => 1, 'published' => 1]);

        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'language' => 'de', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'language' => 'de', 'subscription' => 1, 'createdAt' => 2]);
        $this->connection->insert('tl_consho_order_item', ['id' => 5, 'pid' => 90, 'productName' => 'Kräutertee-Abo', 'lineType' => 'product', 'taxRate' => '10.00']);
        // The newest bill names today's rate.
        $this->connection->insert('tl_consho_order_item', ['id' => 6, 'pid' => 91, 'productName' => 'Kräutertee-Abo', 'lineType' => 'product', 'taxRate' => '20.00']);

        $base = ['firstOrder' => 90, 'orderItem' => 5, 'quantity' => '2.0000', 'currency' => 'EUR', 'termLength' => 1, 'termUnit' => 'month', 'billingInterval' => 1, 'renewal' => 'sameTerm', 'state' => 'active'];

        $this->subscription(1, $base);
        $this->subscription(2, ['renewal' => 'indefinite', 'state' => 'paused'] + $base);
        // No candidates: ended, cancelled, renewed, "Endet".
        $this->subscription(3, ['state' => 'ended'] + $base);
        $this->subscription(4, ['endsAt' => self::NOW + 86_400] + $base);
        $this->subscription(5, ['renewedBy' => 9] + $base);
        $this->subscription(6, ['renewal' => 'ends'] + $base);
    }

    public function testNurLaufendeOhneEndeWerdenGefragt(): void
    {
        $this->offerRun()->run(self::NOW);

        self::assertSame([1, 2], $this->asked);
    }

    public function testDasAngebotGehtHinausUndZaehlt(): void
    {
        $made = $this->offerRun(due: [1 => $this->due(1, 'offer')])->run(self::NOW);

        self::assertCount(1, $made['offers']);
        self::assertSame(['sent 41'], $this->marks);

        $mail = $this->mails[0];
        self::assertSame('offer', $mail['event']);
        self::assertSame(91, $mail['order'], 'the newest order of the subscription');
        self::assertSame('WEB-00001-1', $mail['subscription']);
        self::assertSame('Kräutertee-Abo', $mail['product']);
        // Per period, gross at the newest rate: 2 × 10 × 1.2, 2 × 12 × 1.2.
        self::assertSame("24,00\u{a0}€", $mail['oldPrice']);
        self::assertSame("28,80\u{a0}€", $mail['newPrice']);
        self::assertSame('perMonth', $mail['priceKey']);
        self::assertTrue($mail['taxed']);
        self::assertArrayNotHasKey('net', $mail);
        self::assertSame('https://shop.test/seite-77.html?consho_price_offer=1', $mail['answerUrl'], 'straight to the page that asks');
        self::assertSame('https://shop.test/bestellung', $mail['view']);
    }

    /**
     * P117: shown like the conditions text and the reminder — a business
     * customer taxed at home reads gross as well, the amount on the bill.
     */
    public function testFirmenkundeLiestWieAufDerRechnung(): void
    {
        $this->connection->update('tl_consho_subscription', ['businessCustomer' => 1], ['id' => 1]);
        $this->offerRun(due: [1 => $this->due(1, 'offer')])->run(self::NOW);

        self::assertSame("24,00\u{a0}€", $this->mails[0]['oldPrice']);
        self::assertSame("28,80\u{a0}€", $this->mails[0]['newPrice']);
        self::assertTrue($this->mails[0]['taxed']);
    }

    public function testReverseChargeLiestNettoOhneSteuer(): void
    {
        $this->connection->update('tl_consho_subscription', ['businessCustomer' => 1, 'reverseCharge' => 1], ['id' => 1]);
        $this->offerRun(due: [1 => $this->due(1, 'offer')])->run(self::NOW);

        self::assertSame("20,00\u{a0}€", $this->mails[0]['oldPrice']);
        self::assertSame("24,00\u{a0}€", $this->mails[0]['newPrice']);
        self::assertFalse($this->mails[0]['taxed']);
    }

    /**
     * P135: an offer whose mail did not leave was never made.
     */
    public function testOhneVersandZurueckgenommen(): void
    {
        $this->mailLeaves = false;
        $made = $this->offerRun(due: [1 => $this->due(1, 'offer')])->run(self::NOW);

        self::assertSame([], $made['offers']);
        self::assertSame(['withdraw 41'], $this->marks);
    }

    /**
     * Without "Meine Abos" the customer could not answer, and silence has
     * consequences: no offer at all.
     */
    public function testOhneMeineAbosKeinAngebot(): void
    {
        $this->connection->update('tl_page', ['consho_subscriptionsPage' => 0], ['id' => 1]);
        $made = $this->offerRun(due: [1 => $this->due(1, 'offer')])->run(self::NOW);

        self::assertSame([], $made['offers']);
        self::assertSame([], $this->mails);
        self::assertSame(['withdraw 41'], $this->marks);
    }

    /**
     * A page the customer cannot open counts as none — unpublished, or out
     * of its start/stop window.
     *
     * @param array<string, mixed> $page
     */
    #[DataProvider('unreachablePages')]
    public function testMeineAbosOfflineKeinAngebot(array $page): void
    {
        $this->connection->update('tl_page', $page, ['id' => 77]);
        $made = $this->offerRun(due: [1 => $this->due(1, 'offer')])->run(self::NOW);

        self::assertSame([], $made['offers']);
        self::assertSame([], $this->mails);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreachablePages(): iterable
    {
        yield 'unpublished' => [['published' => 0]];
        yield 'not yet' => [['start' => (string) (time() + 3600)]];
        yield 'no longer' => [['stop' => (string) (time() - 3600)]];
    }

    public function testMeineAbosImFensterMitAngebot(): void
    {
        $this->connection->update('tl_page', ['start' => (string) (time() - 3600), 'stop' => (string) (time() + 3600)], ['id' => 77]);
        $made = $this->offerRun(due: [1 => $this->due(1, 'offer')])->run(self::NOW);

        self::assertCount(1, $made['offers']);
    }

    /**
     * A decrease needs no answer, so it goes without the page too.
     */
    public function testSenkungAuchOhneMeineAbos(): void
    {
        $this->connection->update('tl_page', ['consho_subscriptionsPage' => 0], ['id' => 1]);
        $made = $this->offerRun(due: [1 => $this->due(1, 'decrease')])->run(self::NOW);

        self::assertCount(1, $made['offers']);
        self::assertSame('decrease', $this->mails[0]['event']);
        self::assertSame('', $this->mails[0]['answerUrl']);
    }

    /**
     * The mail is out, its mark fails: logged, not taken back (the customer
     * has it), and the next subscription still gets its offer.
     */
    public function testVersandmarkeScheitertDieAnderenLaufenWeiter(): void
    {
        $this->sentFails = [41];
        $made = $this->offerRun(due: [1 => $this->due(1, 'offer'), 2 => $this->due(2, 'offer')])->run(self::NOW);

        self::assertSame([1, 2], $this->asked);
        self::assertSame([42], array_map(static fn (PriceOfferDue $d): int => $d->offerId, $made['offers']));
        self::assertSame(['sent 42'], $this->marks);
        self::assertCount(2, $this->mails);
    }

    public function testEinFehlerHaeltDieAnderenNichtAuf(): void
    {
        $made = $this->offerRun(due: [2 => $this->due(2, 'offer')], failing: [1])->run(self::NOW);

        self::assertSame([1, 2], $this->asked);
        self::assertCount(1, $made['offers']);
    }

    /**
     * Which offers each stage asks about, and in which order: void, lapse,
     * reminder, then new offers.
     */
    public function testDieStufenFragenDieRichtigenAngebote(): void
    {
        $this->offerRow(70, 1, ['state' => 'open', 'mailSentAt' => 5, 'deadlineAt' => self::NOW - 60]);
        $this->offerRow(71, 2, ['state' => 'open', 'mailSentAt' => 5, 'deadlineAt' => self::NOW + 86_400]);
        // Not yet out, a deadline beyond the reminder's reach, settled: no
        // stage. Whether one was reminded already is asked under the lock.
        $this->offerRow(72, 1, ['state' => 'open', 'mailSentAt' => 0, 'deadlineAt' => self::NOW - 60, 'effectiveAt' => self::NOW + 1]);
        $this->offerRow(73, 2, ['state' => 'open', 'mailSentAt' => 5, 'deadlineAt' => self::NOW + 9 * 86_400 + 1, 'effectiveAt' => self::NOW + 2]);
        // The farthest a due reminder can be from its deadline: from 00:00
        // seven days before to 23:59:59, a summer-time hour on top.
        $this->offerRow(78, 2, ['state' => 'open', 'mailSentAt' => 5, 'deadlineAt' => self::NOW + 8 * 86_400 + 3600, 'effectiveAt' => self::NOW + 4]);
        $this->offerRow(74, 1, ['state' => 'declined', 'mailSentAt' => 5, 'deadlineAt' => self::NOW - 60, 'effectiveAt' => self::NOW + 3]);
        // Subscription 3 has ended: void; an agreed one of subscription 4 ending on its day too.
        $this->offerRow(75, 3, ['state' => 'open', 'mailSentAt' => 5, 'deadlineAt' => self::NOW + 86_400]);
        $this->offerRow(76, 4, ['state' => 'accepted', 'mailSentAt' => 5, 'effectiveAt' => self::NOW + 86_400]);
        $this->offerRow(77, 1, ['state' => 'accepted', 'appliedAt' => 9, 'effectiveAt' => self::NOW - 86_400]);
        $this->voids = [75 => 'WEB-00003-1', 76 => 'WEB-00004-1'];

        $result = $this->offerRun()->run(self::NOW);

        self::assertSame(['WEB-00003-1', 'WEB-00004-1'], $result['voided']);
        self::assertSame(['void? 75', 'void? 76', 'lapse? 70', 'remind? 71', 'remind? 75', 'remind? 78'], $this->marks);
    }

    /**
     * P122: the lapse stands whether or not its mail leaves; a mail that
     * did not is logged for the operator.
     */
    public function testFristablaufMitUndOhneMail(): void
    {
        $this->offerRow(70, 1, ['state' => 'open', 'mailSentAt' => 5, 'deadlineAt' => self::NOW - 60]);
        $this->lapses = [70 => new PriceOfferDue(70, 1, 'WEB-00001-1', 'lapsedConsumer', '10.0000', '12.0000', self::NOW + 30 * 86_400, self::NOW - 60)];

        $result = $this->offerRun()->run(self::NOW);
        self::assertCount(1, $result['lapsed']);
        self::assertSame('lapsedConsumer', $this->mails[0]['event']);
        self::assertContains('answerSent 70', $this->marks);

        $this->marks = [];
        $this->mailLeaves = false;
        $result = $this->offerRun()->run(self::NOW);
        self::assertCount(1, $result['lapsed']);
        self::assertNotContains('answerSent 70', $this->marks);
    }

    public function testErinnerungOhneVersandWirdWiederholt(): void
    {
        $this->offerRow(71, 2, ['state' => 'open', 'mailSentAt' => 5, 'deadlineAt' => self::NOW + 86_400]);
        $this->reminders = [71 => new PriceOfferDue(71, 2, 'WEB-00002-1', 'reminder', '10.0000', '12.0000', self::NOW + 30 * 86_400, self::NOW + 86_400)];

        self::assertCount(1, $this->offerRun()->run(self::NOW)['reminders']);
        self::assertSame('reminder', $this->mails[0]['event']);
        self::assertSame('https://shop.test/seite-77.html?consho_price_offer=2', $this->mails[0]['answerUrl']);

        $this->mailLeaves = false;
        self::assertSame([], $this->offerRun()->run(self::NOW)['reminders']);
        self::assertContains('unremind 71', $this->marks);
    }

    /**
     * P119, P137: agreed — the confirmation, with its copy.
     */
    public function testZustimmungWirdBestaetigt(): void
    {
        $this->answered = new PriceOfferAnswered(new PriceOfferDue(70, 1, 'WEB-00001-1', 'accepted', '10.0000', '12.0000', self::NOW + 30 * 86_400, self::NOW + 86_400), true);

        $answered = $this->offerRun()->answer(70, 1, true, 'Jane', self::NOW);
        self::assertNotNull($answered);
        self::assertSame([70, true, true], [$answered->offer->offerId, $answered->accepted, $answered->mailed]);
        self::assertSame('accepted', $this->mails[0]['event']);
        self::assertSame(['answerSent 70'], $this->marks);

        // The page must not claim a mail that did not leave.
        $this->marks = [];
        $this->mailLeaves = false;
        self::assertFalse($this->offerRun()->answer(70, 1, true, 'Jane', self::NOW)?->mailed);
        self::assertSame([], $this->marks);
    }

    /**
     * P121: declined — confirmed like any cancellation, by the recorder.
     */
    public function testAblehnungWieJedeKuendigungBestaetigt(): void
    {
        $declaration = new CancellationDeclaration(1, CancellationSource::PriceChange, CancellationKind::Ordinary, 'Jane', self::NOW + 30 * 86_400);
        $result = new CancellationResult(CancellationOutcome::Recorded, 9, 'WEB-00001-1-K1', 'WEB-00001-1');
        $this->answered = new PriceOfferAnswered(new PriceOfferDue(70, 1, 'WEB-00001-1', 'declined', '10.0000', '12.0000', self::NOW + 30 * 86_400, self::NOW + 86_400), false, $declaration, $result);

        $recorder = $this->createMock(CancellationRecorder::class);
        $recorder->expects(self::once())->method('confirmRecorded')->with($declaration, $result)
            ->willReturn(new CancellationResult(CancellationOutcome::Recorded, 9, 'WEB-00001-1-K1', 'WEB-00001-1', confirmed: true));

        $this->offerRun(recorder: $recorder)->answer(70, 1, false, 'Jane', self::NOW);

        self::assertSame([], $this->mails, 'no price offer mail of its own');
        self::assertSame(['answerSent 70'], $this->marks);
    }

    public function testPeriodenbetrag(): void
    {
        self::assertSame('24.0000', SubscriptionPriceOfferRun::periodAmount('10.0000', '2.0000', '20'));
        self::assertSame('20.0000', SubscriptionPriceOfferRun::periodAmount('10.0000', '2.0000', '0'));
        self::assertSame('10.7000', SubscriptionPriceOfferRun::periodAmount('10.0000', '1', '7.00'));
    }

    private function due(int $subscriptionId, string $event): PriceOfferDue
    {
        return new PriceOfferDue(40 + $subscriptionId, $subscriptionId, \sprintf('WEB-%05d-1', $subscriptionId), $event, '10.0000', 'decrease' === $event ? '8.0000' : '12.0000', self::NOW + 56 * 86_400, self::NOW + 26 * 86_400);
    }

    /**
     * @param array<int, PriceOfferDue> $due
     * @param list<int>                 $failing
     */
    private function offerRun(array $due = [], array $failing = [], CancellationRecorder|null $recorder = null): SubscriptionPriceOfferRun
    {
        $offers = $this->createMock(SubscriptionPriceOffers::class);
        $offers->method('prepare')->willReturnCallback(function (int $id) use ($due, $failing): PriceOfferDue|null {
            $this->asked[] = $id;

            if (\in_array($id, $failing, true)) {
                throw new \RuntimeException('lock wait timeout');
            }

            return $due[$id] ?? null;
        });
        $offers->method('sent')->willReturnCallback(function (int $offerId): void {
            if (\in_array($offerId, $this->sentFails, true)) {
                throw new \RuntimeException('deadlock');
            }

            $this->marks[] = 'sent '.$offerId;
        });
        $offers->method('withdraw')->willReturnCallback(function (int $offerId): void {
            $this->marks[] = 'withdraw '.$offerId;
        });
        $offers->method('void')->willReturnCallback(function (int $offerId): string|null {
            $this->marks[] = 'void? '.$offerId;

            return $this->voids[$offerId] ?? null;
        });
        $offers->method('lapse')->willReturnCallback(function (int $offerId): PriceOfferDue|null {
            $this->marks[] = 'lapse? '.$offerId;

            return $this->lapses[$offerId] ?? null;
        });
        $offers->method('remind')->willReturnCallback(function (int $offerId): PriceOfferDue|null {
            $this->marks[] = 'remind? '.$offerId;

            return $this->reminders[$offerId] ?? null;
        });
        $offers->method('answerSent')->willReturnCallback(function (int $offerId): void {
            $this->marks[] = 'answerSent '.$offerId;
        });
        $offers->method('unremind')->willReturnCallback(function (int $offerId): void {
            $this->marks[] = 'unremind '.$offerId;
        });

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('priceOffer')->willReturnCallback(function (string $event, int $orderId, array $offer, string $viewUrl): bool {
            $this->mails[] = ['event' => $event, 'order' => $orderId, 'view' => $viewUrl] + $offer;

            return $this->mailLeaves;
        });

        $source = $this->createStub(OrderConfirmationSourceInterface::class);
        $source->method('rootOf')->willReturn(1);

        $offers->method('answer')->willReturnCallback(fn (): PriceOfferAnswered|null => $this->answered);

        return new SubscriptionPriceOfferRun($this->connection, $offers, $links, $source, $mail, new MoneyFormatter(), $recorder ?? $this->createStub(CancellationRecorder::class), pageUrls: $this->pageUrls());
    }

    private function pageUrls(): ShopPageUrls
    {
        $page = $this->createStub(PageModel::class);
        $pageAdapter = $this->createStub(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(static fn (string $method, array $args): PageModel|null => 'findById' === $method && 77 === $args[0] ? $page : null);

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $urls = $this->createStub(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(static fn (object $content): string => $content === $page ? 'https://shop.test/seite-77.html' : '');

        return new ShopPageUrls($framework, new ShopResolver($framework), $urls);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function offerRow(int $id, int $subscriptionId, array $values): void
    {
        $this->connection->insert('tl_consho_subscription_price', $values + ['id' => $id, 'pid' => $subscriptionId, 'effectiveAt' => self::NOW + $id * 86_400, 'oldUnitNet' => '10.0000', 'newUnitNet' => '12.0000']);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function subscription(int $id, array $values): void
    {
        $this->connection->insert('tl_consho_subscription', $values + ['id' => $id, 'subscriptionNumber' => \sprintf('WEB-%05d-1', $id)]);
    }
}
