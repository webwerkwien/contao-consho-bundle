<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Intl\Countries;
use Doctrine\DBAL\Configuration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result as DriverResult;
use Doctrine\DBAL\Driver\Statement as DriverStatement;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementQuery;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentRepository;
use Webwerkwien\ContaoConshoBundle\Document\DbalDocumentSource;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderStatusRepository;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderWriter;
use Webwerkwien\ContaoConshoBundle\Order\DbalPaymentChangeRepository;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeAllocator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderFactory;
use Webwerkwien\ContaoConshoBundle\Order\OrderProductRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusWriterInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\StatusFlags;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentMethodRepository;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\DbalSubscriptionLock;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBiller;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingMethod;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionBillingRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Cron\SubscriptionCron;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionChargeFallback;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCharger;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycleRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycle;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPauseRefused;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;

/**
 * Pausing a subscription (P102–P108) with real building blocks: real pause, real biller
 * with follow-up order and invoice, real lifecycle, real
 * cancellation date, real entitlement query, real delinquency. Doubled are
 * only catalogue, mail, order page and archive.
 *
 * The subscription: monthly subscription from 31.01.2026 10:15, one year, then the same term
 * (business customer), notice period 30 days, pause up to 3 months per year.
 */
final class SubscriptionPauseEndToEndTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    private string $zone;

    public static function withoutRowLock(string $sql): string
    {
        return (string) preg_replace('/\s+FOR\s+UPDATE\s*$/i', '', $sql, 1);
    }

    protected function setUp(): void
    {
        $this->zone = date_default_timezone_get();
        date_default_timezone_set(self::ZONE);

        $configuration = (new Configuration())->setMiddlewares([new class implements Middleware {
            public function wrap(Driver $driver): Driver
            {
                return new class($driver) extends AbstractDriverMiddleware {
                    public function connect(array $params): DriverConnection
                    {
                        return new class(parent::connect($params)) extends AbstractConnectionMiddleware {
                            public function prepare(string $sql): DriverStatement
                            {
                                return parent::prepare(SubscriptionPauseEndToEndTest::withoutRowLock($sql));
                            }

                            public function query(string $sql): DriverResult
                            {
                                return parent::query(SubscriptionPauseEndToEndTest::withoutRowLock($sql));
                            }

                            public function exec(string $sql): int|string
                            {
                                return parent::exec(SubscriptionPauseEndToEndTest::withoutRowLock($sql));
                            }
                        };
                    }
                };
            }
        }]);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $configuration);

        foreach ([
            'tl_consho_shop', 'tl_consho_number_range', 'tl_consho_order_status', 'tl_consho_payment_method', 'tl_consho_translation',
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_payment', 'tl_consho_delivery', 'tl_consho_delivery_item',
            'tl_consho_document', 'tl_consho_subscription', 'tl_consho_subscription_pause', 'tl_consho_entitlement',
            'tl_consho_withdrawal', 'tl_consho_withdrawal_item', 'tl_consho_payment_attempt', 'tl_consho_cancellation',
        ] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT '', hash TEXT NOT NULL DEFAULT '')");

        $this->seed();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zone);
    }

    /**
     * P103–P105: two months from the end of the current period. Everything after
     * moves by two months; during the pause no billing and no access,
     * afterwards it continues with the next period.
     */
    public function testDiePauseVerschiebtAllesUndDanachGehtEsWeiter(): void
    {
        $announced = $this->at('2026-02-10 09:00');

        self::assertSame([1, 2, 3], $this->pause()->options($this->row(), $announced));
        self::assertSame(range(1, 12), $this->pause()->options($this->row(), $announced, true), 'der Betreiber über das Kontingent hinaus (P101)');

        $scheduled = $this->pause()->schedule(1, 2, 'account', $announced);

        self::assertSame(['2026-02-28 10:15', '2026-04-30 10:15'], [$this->local($scheduled->from), $this->local($scheduled->until)]);
        $row = $this->row();
        self::assertSame([2, '2026-04-30 10:15', '2027-03-31 10:15', 'active'], [(int) $row['pausedMonths'], $this->local($row['nextBillingAt']), $this->local($row['minimumEndsAt']), $row['state']]);
        self::assertSame([], $this->pause()->options($this->row(), $announced), 'eine Pause zur Zeit');

        // At the end of the period: nothing billed, the subscription is paused.
        $periodEnd = $this->at('2026-02-28 11:00');
        self::assertSame(['paused' => ['WEB-00090-1'], 'resumed' => []], $this->pause()->run($periodEnd));
        self::assertSame([], $this->billingRun()->run($periodEnd));
        self::assertSame('paused', $this->row()['state']);

        // P105: access ends with the paid period.
        $query = new EntitlementQuery($this->connection);
        self::assertTrue($query->isActive(30, $this->at('2026-02-28 10:00')));
        self::assertFalse($query->isActive(30, $this->at('2026-03-15 12:00')));

        // P108: delinquency keeps checking — with nothing overdue it stays paused.
        $this->delinquencyRun()->run($this->at('2026-03-15 12:00'));
        self::assertSame('paused', $this->row()['state']);

        // At the end of the pause: active again and billed in the same run.
        $resumedAt = $this->at('2026-04-30 11:00');
        self::assertSame(['paused' => [], 'resumed' => ['WEB-00090-1']], $this->pause()->run($resumedAt));
        $billed = $this->billingRun()->run($resumedAt);

        self::assertCount(1, $billed);
        self::assertSame(['2026-04-30 10:15', '2026-05-31 10:15'], [$this->local($billed[0]->periodFrom), $this->local($billed[0]->periodUntil)]);
        self::assertTrue($query->isActive(30, $this->at('2026-05-15 12:00')), 'der Zugang ist zurück');
        self::assertSame('2026-05-31 10:15', $this->local($this->row()['nextBillingAt']));

        // P104: the cancellation date from the shifted calendar.
        $term = new CancellationTerm(new SubscriptionCalendar(self::ZONE));
        self::assertSame('2027-03-31 10:15', $this->local($term->ordinary($this->row(), $this->at('2027-02-10 12:00'), null)->effectiveAt));
        // Too late for this end: the next one of the same term — from the shifted anchor.
        self::assertSame('2028-03-31 10:15', $this->local($term->ordinary($this->row(), $this->at('2027-03-10 12:00'), null)->effectiveAt));
    }

    /**
     * A pause later in the contract: the next period begins at the end of the
     * pause, not at the first boundary after the last paid one — with a pause of
     * whole periods the boundaries fall on the same days, after all.
     */
    public function testNachEinerSpaetenPauseBeginntDiePeriodeAmEndeDerPause(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'periodFrom' => $this->at('2026-04-30 10:15'), 'periodUntil' => $this->at('2026-05-31 10:15'), 'nextBillingAt' => $this->at('2026-05-31 10:15'),
        ], ['id' => 1]);
        $this->connection->update('tl_consho_entitlement', ['validUntil' => $this->at('2026-05-31 10:15')], ['id' => 30]);

        $this->pause()->schedule(1, 1, 'account', $this->at('2026-05-10 09:00'));
        self::assertSame('2026-06-30 10:15', $this->local($this->row()['pauseUntil']));

        $this->pause()->run($this->at('2026-05-31 11:00'));
        self::assertSame([], $this->billingRun()->run($this->at('2026-05-31 11:00')));
        $this->pause()->run($this->at('2026-06-30 11:00'));
        $billed = $this->billingRun()->run($this->at('2026-06-30 11:00'));

        self::assertCount(1, $billed);
        self::assertSame(['2026-06-30 10:15', '2026-07-31 10:15'], [$this->local($billed[0]->periodFrom), $this->local($billed[0]->periodUntil)]);
    }

    /**
     * P106/P108: a payment during the pause leaves the subscription paused — the
     * delinquency check brings back only a suspended one, otherwise "active again" would come in the middle of
     * the pause.
     */
    public function testEineZahlungInDerPauseWecktNichts(): void
    {
        $this->pause()->schedule(1, 2, 'account', $this->at('2026-02-10 09:00'));
        $this->pause()->run($this->at('2026-03-01 09:00'));

        self::assertSame([], $this->delinquencyRun()->afterPayment(90, $this->at('2026-03-02 09:00')));
        self::assertSame('paused', $this->row()['state']);
    }

    /**
     * A pause from the last period of the minimum term. Without
     * the fix, billing waited for the transition and the transition
     * for billing — forever. Per renewal type, and the yearly subscription,
     * in which every pause lies there — a state in which only this path can fire.
     *
     * @return iterable<string, array{array<string, mixed>, int, string, string, string}>
     */
    public static function pausenAmLaufzeitende(): iterable
    {
        yield 'Monatsabo, gleiche Laufzeit' => [['renewal' => 'sameTerm'], 2, '2027-03-31 10:15', '2028-03-31 10:15', '2027-04-30 10:15'];
        yield 'Monatsabo, unbefristet' => [['renewal' => 'indefinite'], 2, '2027-03-31 10:15', '', '2027-04-30 10:15'];
        yield 'Jahresabo, gleiche Laufzeit' => [['renewal' => 'sameTerm', 'billingInterval' => 12, 'pauseMonths' => 12], 12, '2028-01-31 10:15', '2029-01-31 10:15', '2029-01-31 10:15'];
    }

    /**
     * @param array<string, mixed> $rule
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pausenAmLaufzeitende')]
    public function testEinePauseAmLaufzeitendeBlockiertNichts(array $rule, int $months, string $pauseEnds, string $nextTermEnds, string $firstPeriodEnds): void
    {
        $lastPeriodFrom = 12 === $months ? '2026-01-31 10:15' : '2026-12-31 10:15';
        $this->connection->update('tl_consho_subscription', $rule + [
            'periodFrom' => $this->at($lastPeriodFrom), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);

        $this->pause()->schedule(1, $months, 'account', $this->at('2027-01-10 09:00'));
        self::assertSame($pauseEnds, $this->local($this->row()['minimumEndsAt']), 'die Laufzeit endet mit der Pause');

        // The real cron at the end of the pause: its
        // order and its candidate search, not a transcript of them.
        $at = $this->at($pauseEnds) + 2_700;
        $lifecycle = new SubscriptionLifecycle($this->connection, new DbalSubscriptionLock($this->connection), new SubscriptionCalendar(self::ZONE));
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        (new SubscriptionCron(
            $this->billingRun(),
            $this->delinquencyRun(),
            new SubscriptionLifecycleRun($this->connection, $lifecycle, $links, $this->createStub(OrderConfirmationSourceInterface::class), $this->createStub(OrderEventMail::class)),
            $this->createStub(SubscriptionCharger::class),
            $this->createStub(SubscriptionChargeFallback::class),
            $this->pause(),
            static fn (): int => $at,
        ))();

        $billed = $this->connection->fetchAllAssociative('SELECT servicePeriodFrom, servicePeriodUntil FROM tl_consho_order WHERE subscription = 1');
        self::assertCount(1, $billed, 'genau eine Periode nach der Pause');
        self::assertSame([$pauseEnds, $firstPeriodEnds], [$this->local($billed[0]['servicePeriodFrom']), $this->local($billed[0]['servicePeriodUntil'])]);
        // Out of the pause. Not "active": DocumentIssuer issues with the
        // server's clock, so the payment term lies before $at, and the
        // real delinquency check in the same run suspends the subscription (as in the Abo-5 test).
        self::assertNotSame('paused', $this->row()['state']);

        if ('' === $nextTermEnds) {
            self::assertSame('indefinite', $this->row()['phase']);
        } else {
            self::assertSame($nextTermEnds, $this->local($this->row()['minimumEndsAt']));
        }
    }

    /**
     * A subscription that ends anyway is not paused in its last period
     * — the pause would only push the end out by unpaid months.
     */
    public function testEinEndendesAboNichtInDerLetztenPeriode(): void
    {
        $this->connection->update('tl_consho_subscription', [
            'renewal' => 'ends', 'periodFrom' => $this->at('2026-12-31 10:15'), 'periodUntil' => $this->at('2027-01-31 10:15'), 'nextBillingAt' => $this->at('2027-01-31 10:15'),
        ], ['id' => 1]);

        self::assertSame([], $this->pause()->options($this->row(), $this->at('2027-01-10 09:00')));

        // In the middle of the term it is.
        $this->connection->update('tl_consho_subscription', ['periodFrom' => $this->at('2026-01-31 10:15'), 'periodUntil' => $this->at('2026-02-28 10:15'), 'nextBillingAt' => $this->at('2026-02-28 10:15')], ['id' => 1]);
        self::assertSame([1, 2, 3], $this->pause()->options($this->row(), $this->at('2026-02-10 09:00')));
    }

    /**
     * After a cancellation the pause can no longer be withdrawn —
     * the cancellation date was calculated from the shifted term.
     */
    public function testNachDerKuendigungNichtMehrZuruecknehmbar(): void
    {
        $this->pause()->schedule(1, 2, 'account', $this->at('2026-02-10 09:00'));
        $this->connection->update('tl_consho_subscription', ['endsAt' => $this->at('2027-03-31 10:15')], ['id' => 1]);

        // It takes place and stays visible — just not withdrawable.
        self::assertTrue(SubscriptionPause::announced($this->row(), $this->at('2026-02-15 09:00')));
        self::assertFalse(SubscriptionPause::withdrawable($this->row(), $this->at('2026-02-15 09:00')));
        self::assertFalse($this->pause()->withdraw(1, $this->at('2026-02-15 09:00')));
        self::assertSame(2, (int) $this->row()['pausedMonths']);
    }

    /**
     * If the subscription is suspended during the pause and gets paid, it comes back
     * paused — without "active again".
     */
    public function testBezahltInDerPauseKommtPausiertZurueck(): void
    {
        $this->connection->update('tl_consho_order', ['paymentSettledAt' => null, 'grandTotal' => '12.0000', 'paymentDueAt' => $this->at('2026-02-14 23:59')], ['id' => 90]);
        $this->pause()->schedule(1, 2, 'account', $this->at('2026-02-10 09:00'));
        $this->pause()->run($this->at('2026-03-01 09:00'));
        $this->delinquencyRun()->run($this->at('2026-03-20 09:00'));
        self::assertSame('suspended', $this->row()['state']);

        $this->connection->insert('tl_consho_payment', ['pid' => 90, 'amount' => '12.0000', 'tstamp' => $this->at('2026-03-21 09:00')]);

        self::assertSame([], $this->delinquencyRun()->afterPayment(90, $this->at('2026-03-21 09:00')), 'keine Mail „wieder aktiv"');
        self::assertSame('paused', $this->row()['state']);
    }

    /**
     * P108: an invoice from before the pause stays due — its delinquency continues
     * during the pause.
     */
    public function testDerVerzugLaeuftInDerPauseWeiter(): void
    {
        $this->connection->update('tl_consho_order', ['paymentSettledAt' => null, 'grandTotal' => '12.0000', 'paymentDueAt' => $this->at('2026-02-14 23:59')], ['id' => 90]);
        $this->pause()->schedule(1, 2, 'account', $this->at('2026-02-10 09:00'));
        $this->pause()->run($this->at('2026-03-01 09:00'));

        $changes = $this->delinquencyRun()->run($this->at('2026-03-20 09:00'));

        self::assertCount(1, $changes);
        self::assertSame('suspended', $this->row()['state']);
    }

    /**
     * P104: the same term renews from the shifted end — the
     * lifecycle asks the same calendar.
     */
    public function testDieGleicheLaufzeitVerlaengertVomVerschobenenEnde(): void
    {
        $this->pause()->schedule(1, 2, 'account', $this->at('2026-02-10 09:00'));
        $this->connection->update('tl_consho_subscription', ['periodUntil' => $this->at('2027-03-31 10:15'), 'nextBillingAt' => $this->at('2027-03-31 10:15')], ['id' => 1]);

        $lifecycle = new SubscriptionLifecycle($this->connection, new DbalSubscriptionLock($this->connection), new SubscriptionCalendar(self::ZONE));

        self::assertNull($lifecycle->transition(1, $this->at('2027-01-31 11:00')), 'das alte Ende gilt nicht mehr');
        $lifecycle->transition(1, $this->at('2027-03-31 11:00'));
        self::assertSame('2028-03-31 10:15', $this->local($this->row()['minimumEndsAt']));
    }

    /**
     * P107: withdrawing before the start restores exactly what was before; a
     * running pause runs until its end.
     */
    public function testZuruecknehmenNurVorBeginnUndGenauWieVorher(): void
    {
        $before = $this->row();
        $this->pause()->schedule(1, 3, 'account', $this->at('2026-02-10 09:00'));

        self::assertTrue($this->pause()->withdraw(1, $this->at('2026-02-20 09:00')));
        $after = $this->row();

        foreach (['pausedMonths', 'pauseFrom', 'pauseUntil', 'nextBillingAt', 'minimumEndsAt', 'reminderSentAt', 'state'] as $field) {
            self::assertEquals($before[$field], $after[$field], $field);
        }

        self::assertGreaterThan(0, (int) $this->connection->fetchOne('SELECT withdrawnAt FROM tl_consho_subscription_pause WHERE pid = 1'));
        self::assertFalse($this->pause()->withdraw(1, $this->at('2026-02-20 09:00')), 'einmal');
        self::assertSame([1, 2, 3], $this->pause()->options($this->row(), $this->at('2026-02-20 09:00')), 'zurückgenommen zählt nicht ins Kontingent');

        // Started: no longer.
        $this->pause()->schedule(1, 1, 'account', $this->at('2026-02-20 09:00'));
        self::assertFalse($this->pause()->withdraw(1, $this->at('2026-03-01 09:00')));
        self::assertSame(1, (int) $this->row()['pausedMonths']);
    }

    /**
     * P102: the allowance applies per contract year, counted from the real start.
     */
    public function testDasKontingentJeVertragsjahr(): void
    {
        $this->pause()->schedule(1, 2, 'account', $this->at('2026-02-10 09:00'));
        $this->pause()->run($this->at('2026-02-28 11:00'));
        $this->pause()->run($this->at('2026-04-30 11:00'));
        $this->billingRun()->run($this->at('2026-04-30 11:00'));

        self::assertSame([1], $this->pause()->options($this->row(), $this->at('2026-05-10 09:00')));

        $this->expectException(SubscriptionPauseRefused::class);
        $this->pause()->schedule(1, 2, 'account', $this->at('2026-05-10 09:00'));
    }

    /**
     * P103 and P106: only an active subscription, billed per period, without a
     * known end.
     */
    public function testWannEsNichtGeht(): void
    {
        $now = $this->at('2026-02-10 09:00');
        $cases = [
            'ohne Kontingent' => ['pauseMonths' => 0],
            'eine Zahlung je Laufzeit' => ['billingInterval' => 0],
            'gekündigt' => ['endsAt' => $this->at('2026-05-31 10:15')],
            'ruhend' => ['state' => 'suspended'],
            'verlängert' => ['renewedBy' => 9],
            'Abrechnung von Hand verschoben' => ['nextBillingAt' => $this->at('2026-03-05 10:15')],
        ];

        foreach ($cases as $case => $change) {
            self::assertSame([], $this->pause()->options([...$this->row(), ...$change], $now), $case);
        }
    }

    // ── Building blocks ─────────────────────────────────────────────────────

    private function pause(): SubscriptionPause
    {
        return new SubscriptionPause($this->connection, new DbalSubscriptionLock($this->connection), new SubscriptionCalendar(self::ZONE));
    }

    private function billingRun(): SubscriptionBillingRun
    {
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        return new SubscriptionBillingRun($this->connection, $this->biller(), $links, $this->createStub(OrderEventMail::class));
    }

    private function delinquencyRun(): SubscriptionDelinquencyRun
    {
        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        return new SubscriptionDelinquencyRun(
            $this->connection,
            new SubscriptionDelinquency($this->connection, new DbalSubscriptionLock($this->connection), $this->balances(), new SubscriptionCalendar(self::ZONE)),
            $this->balances(),
            $links,
            $this->createStub(OrderEventMail::class),
        );
    }

    private function biller(): SubscriptionBiller
    {
        $methods = new DbalPaymentMethodRepository($this->connection);
        $handlers = new PaymentHandlers(new ServiceLocator([
            InvoicePaymentHandler::getType() => static fn (): PaymentHandlerInterface => new InvoicePaymentHandler(),
        ]));

        $rates = $this->createStub(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturn('20.0000');
        $countries = $this->createStub(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT']);

        $factory = new OrderFactory(
            new NumberRangeAllocator($this->connection),
            $this->createStub(CartLinePriceSource::class),
            new TaxResolver($rates, new CountryProvider($countries)),
            $this->createStub(OrderProductRepositoryInterface::class),
            new DbalOrderWriter($this->connection),
        );

        $issuer = new DocumentIssuer(
            new DbalDocumentRepository($this->connection),
            new NumberRangeAllocator($this->connection),
            new DocumentPayloadBuilder(),
            new DbalDocumentSource($this->connection, new TranslationStorage($this->connection), new ShopLanguages($this->connection), $this->createStub(VirtualFilesystemInterface::class), $this->balances()),
        );

        $writer = new class($this->connection) implements OrderStatusWriterInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function write(int $orderId, StatusFlags $status, int $now): void
            {
                $this->connection->update('tl_consho_order', ['orderStatus' => $status->id, 'tstamp' => $now], ['id' => $orderId]);
            }
        };

        $deliveries = new DeliveryReported(
            $this->connection,
            new DbalPaymentChangeRepository($this->connection),
            $this->balances(),
            $methods,
            $handlers,
            $issuer,
            $this->createStub(DocumentArchiverInterface::class),
            new OrderStatusAdvancer($this->connection, new DbalOrderStatusRepository($this->connection), $this->balances(), $writer),
            $this->createStub(OrderPageLink::class),
            $this->createStub(OrderEventMail::class),
            new MoneyFormatter(),
        );

        return new SubscriptionBiller(
            $this->connection,
            new DbalSubscriptionLock($this->connection),
            $factory,
            new SubscriptionBillingMethod($methods),
            $deliveries,
            new SubscriptionCalendar(self::ZONE),
            $this->createStub(DocumentArchiverInterface::class),
        );
    }

    private function balances(): OrderBalanceReader
    {
        return new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = 1');
        self::assertIsArray($row);

        return $row;
    }

    private function seed(): void
    {
        $c = $this->connection;

        $c->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'country' => 'AT', 'vatId' => 'ATU12345678', 'fallbackLanguage' => 'de', 'paymentMethods' => serialize(['6']), 'invoiceCompany' => 'Muster', 'invoiceCountry' => 'AT']);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'order', 'prefix' => 'WEB-', 'padding' => 5, 'current' => 90]);
        $c->insert('tl_consho_number_range', ['pid' => 1, 'type' => 'invoice', 'prefix' => 'RE-', 'padding' => 5, 'current' => 0]);
        $c->insert('tl_consho_payment_method', ['id' => 6, 'type' => 'invoice', 'title' => 'Auf Rechnung', 'published' => 1, 'jsonData' => json_encode(['dueDays' => 14], JSON_THROW_ON_ERROR)]);

        $start = $this->at('2026-01-31 10:15');

        $c->insert('tl_consho_order', [
            'id' => 90, 'orderNumber' => 'WEB-00090', 'shop' => 1, 'member' => 7, 'language' => 'de', 'currency' => 'EUR', 'createdAt' => $start,
            'firstname' => 'John', 'lastname' => 'Muster', 'email' => 'kunde@example.org', 'company' => 'Muster GmbH',
            'billingStreet' => 'Hauptstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingCountry' => 'AT',
            'shippingCountry' => 'AT', 'businessCustomer' => 1, 'paymentMethod' => 6, 'paymentMethodTitle' => 'Auf Rechnung',
            'paymentSettledAt' => $start,
        ]);
        $c->insert('tl_consho_order_item', ['id' => 2, 'pid' => 90, 'lineType' => 'product', 'product' => 4, 'productName' => 'Mitgliedschaft', 'sku' => 'M-1', 'kind' => 'service', 'quantity' => '1.0000', 'lineTotal' => '12.0000']);

        $c->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'shop' => 1, 'member' => 7, 'product' => 4, 'firstOrder' => 90, 'orderItem' => 2,
            'rule' => 1, 'ruleTitle' => 'Monatsabo', 'termLength' => 1, 'termUnit' => 'year', 'billingInterval' => 1, 'renewal' => 'sameTerm',
            'noticeDays' => 30, 'reminderDays' => 60, 'graceDays' => 14, 'pauseMonths' => 3,
            'quantity' => '1.0000', 'unitNet' => '10.0000', 'taxClass' => 3, 'currency' => 'EUR', 'businessCustomer' => 1,
            'kind' => 'service', 'terms' => 'Monatsabo, 1 Jahr',
            'startedAt' => $start, 'minimumEndsAt' => $this->at('2027-01-31 10:15'),
            'periodFrom' => $start, 'periodUntil' => $this->at('2026-02-28 10:15'), 'nextBillingAt' => $this->at('2026-02-28 10:15'),
        ]);
        $c->insert('tl_consho_entitlement', ['id' => 30, 'pid' => 90, 'orderItem' => 2, 'subscription' => 1, 'member' => 7, 'validUntil' => $this->at('2026-02-28 10:15')]);
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }

    private function local(mixed $timestamp): string
    {
        return (new \DateTimeImmutable('@'.(int) $timestamp))->setTimezone(new \DateTimeZone(self::ZONE))->format('Y-m-d H:i');
    }
}
