<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Refund;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Cron\RefundPayoutCron;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundResult;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRefundingHandler;

/**
 * Block Zahlung Mollie, 4.3: booked first, paid out after; newest payment
 * first; the rest by hand; never twice.
 */
final class RefundPayoutTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    private FakeRefundingHandler $card;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $log;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_payment_attempt'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->card = new FakeRefundingHandler();
        $this->log = new \ArrayObject();

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'B-1', 'currency' => 'EUR', 'language' => 'de']);

        // Methods: 1 a provider that pays back, 2 prepayment — it cannot.
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 10, 'pid' => 1, 'tokenHash' => 'h10', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 11, 'pid' => 1, 'tokenHash' => 'h11', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 12, 'pid' => 1, 'tokenHash' => 'h12', 'method' => 2, 'methodType' => 'prepayment']);

        $this->payment(101, '30.0000', 100, 10, 'tr_alt');
        $this->payment(102, '20.0000', 200, 11, 'tr_neu');
        $this->payment(103, '50.0000', 300, 12, 'VORKASSE');
        // Typed by the operator: no attempt, no provider.
        $this->payment(104, '15.0000', 400, null, 'Bar');
    }

    public function testErstattbarSindNurZahlungenEinesAnbietersDerErstattenKannNeuesteZuerst(): void
    {
        self::assertSame([102 => '20.0000', 101 => '30.0000'], $this->payout()->refundable(1));
    }

    public function testDerBetragWirdAufDieZahlungenAufgeteiltNeuesteZuerst(): void
    {
        $booked = $this->payout()->book(1, '35', 'W-1', self::NOW);

        self::assertSame([
            ['-20.0000', 102, 'requested'],
            ['-15.0000', 101, 'requested'],
        ], $this->rows($booked));
    }

    public function testWasKeinAnbieterNehmenKannGehtVonHand(): void
    {
        $booked = $this->payout()->book(1, '60', 'W-1', self::NOW);

        self::assertSame([
            ['-20.0000', 102, 'requested'],
            ['-30.0000', 101, 'requested'],
            ['-10.0000', 0, 'manual'],
        ], $this->rows($booked));
    }

    public function testOhneAuszahlungGehtAllesVonHand(): void
    {
        $booked = $this->payout()->book(1, '35', 'W-1', self::NOW, false);

        self::assertSame([['-35.0000', 0, 'manual']], $this->rows($booked));
    }

    public function testNurGegenDieGewaehlteZahlung(): void
    {
        $booked = $this->payout()->book(1, '25', 'Kulanz', self::NOW, true, 101);

        self::assertSame([['-25.0000', 101, 'requested']], $this->rows($booked));
    }

    public function testFruehereErstattungenMindernDasErstattbare(): void
    {
        $this->payout()->book(1, '5', 'W-1', self::NOW);

        self::assertSame([102 => '15.0000', 101 => '30.0000'], $this->payout()->refundable(1));
    }

    public function testEinNegativerBetragWirdAbgelehnt(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->payout()->book(1, '-5', 'W-1', self::NOW);
    }

    public function testErstattenBuchtUndZahltAus(): void
    {
        $this->card->refund = RefundResult::done('re_1');

        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        self::assertSame([['-20.0000', 102, 'done']], $this->rows($booked));
        self::assertSame('re_1', $this->row($booked[0])['payoutReference']);
        self::assertCount(1, $this->card->refunds);
        self::assertSame(['20.0000', 'EUR', 'tr_neu', 'B-1', ''], [
            $this->card->refunds[0]->amount,
            $this->card->refunds[0]->currency,
            $this->card->refunds[0]->paymentReference,
            $this->card->refunds[0]->orderNumber,
            $this->card->refunds[0]->payoutReference,
        ]);
    }

    /**
     * No answer is not a refusal: the request may have gone through. The row
     * stays `sending` — never offered "pay out again", never sent by the
     * cron, settled by hand after checking the provider.
     */
    public function testOhneAntwortBleibtDieErstattungOffenUndWirdNichtWiederholt(): void
    {
        $this->card->refund = new \RuntimeException('Anbieter nicht erreichbar');

        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        self::assertSame([['-20.0000', 102, 'sending']], $this->rows($booked));
        self::assertStringContainsString('Anbieter nicht erreichbar', implode("\n", $this->log->getArrayCopy()));

        self::assertFalse($this->payout()->retry($booked[0], self::NOW + 60));
        $this->payout()->checkPending(self::NOW + 7200);
        self::assertCount(1, $this->card->refunds, 'nie ein zweites Mal gesendet');
        self::assertTrue($this->payout()->markManual($booked[0], self::NOW + 7200));
    }

    /**
     * Marked by hand while the provider was asked: a refusal leaves the mark
     * (the operator paid; sending again would pay twice), money that moved
     * overwrites it — the truth about the money wins.
     */
    public function testVonHandErledigtBleibtBeiAblehnungDesAnbieters(): void
    {
        $payout = $this->payout();
        $booked = $payout->book(1, '20', 'Kulanz', self::NOW);
        $this->card->meanwhile = static function () use ($payout, $booked): void {
            $payout->markManual($booked[0], self::NOW);
        };
        $this->card->refund = RefundResult::failed('abgelehnt');

        $payout->payOut($booked, self::NOW);

        self::assertSame('manual', $this->row($booked[0])['payoutState']);
    }

    public function testVonHandErledigtWeichtDemGeldDasDochZurueckging(): void
    {
        foreach (['done' => [RefundResult::done('re_5'), 'done'], 'pending' => [RefundResult::pending('re_5'), 'requested']] as $case => [$answer, $state]) {
            $this->log->exchangeArray([]);
            $payout = $this->payout();
            $booked = $payout->book(1, '20', 'Kulanz', self::NOW);
            $this->card->meanwhile = static function () use ($payout, $booked): void {
                $payout->markManual($booked[0], self::NOW);
            };
            $this->card->refund = $answer;

            $payout->payOut($booked, self::NOW);

            // Pending: the cron takes it over and asks with the reference.
            self::assertSame([$state, 're_5'], [$this->row($booked[0])['payoutState'], $this->row($booked[0])['payoutReference']], $case);
            self::assertStringContainsString('double payment', implode("\n", $this->log->getArrayCopy()), $case);
        }
    }

    /**
     * Two operators refunding the same payment: the second finds nothing
     * left under the lock and books nothing — not a refund "by hand".
     */
    public function testZweimalErstattenBuchtNichtsVonHand(): void
    {
        $this->card->refund = RefundResult::done('re_1');

        self::assertCount(1, $this->payout()->refund(1, '20', 'Kulanz', self::NOW, 102));
        self::assertSame([], $this->payout()->refund(1, '20', 'Kulanz', self::NOW, 102));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE payoutState = 'manual'"));
        self::assertCount(1, $this->card->refunds);
    }

    public function testOhneSperreDerBestellungWirdNichtsGebucht(): void
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturn(null);
        $payout = new RefundPayout($this->connection, $orders, $this->methods(), new PaymentHandlers(new ServiceLocator(['card' => fn (): PaymentHandlerInterface => $this->card])), $this->logger());

        self::assertSame([], $payout->refund(1, '20', 'Kulanz', self::NOW));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment WHERE amount < 0'));
    }

    public function testDerAnbieterWirdErstNachDemCommitGefragt(): void
    {
        $this->card->connection = $this->connection;
        $this->card->refund = RefundResult::done('re_1');

        $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        self::assertCount(1, $this->card->refunds);
        self::assertFalse($this->card->inTransaction);
    }

    public function testEineAusgezahlteErstattungLaesstSichNichtVonHandVermerken(): void
    {
        $this->card->refund = RefundResult::done('re_1');
        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        self::assertFalse($this->payout()->markManual($booked[0], self::NOW));
        self::assertSame('done', $this->row($booked[0])['payoutState']);
    }

    /**
     * A row the provider answered `pending` carries its reference: payOut()
     * must not send it again, only the cron asks about it.
     */
    public function testEineAusstehendeErstattungWirdNichtNochmalGesendet(): void
    {
        $this->card->refund = RefundResult::pending('re_3');
        $payout = $this->payout();
        $booked = $payout->refund(1, '20', 'Kulanz', self::NOW);

        $payout->payOut($booked, self::NOW);

        self::assertCount(1, $this->card->refunds);
    }

    public function testDerCronUebernimmtEineAblehnungUndEineWiederholungSendetNeu(): void
    {
        $this->card->refund = RefundResult::pending('re_3');
        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        $this->card->state = RefundResult::failed('abgelehnt');
        $this->payout()->checkPending(self::NOW + 3600);
        self::assertSame(['failed', 're_3'], [$this->row($booked[0])['payoutState'], $this->row($booked[0])['payoutReference']]);

        $this->card->refund = RefundResult::done('re_4');
        self::assertTrue($this->payout()->retry($booked[0], self::NOW + 7200));
        self::assertSame(['done', 're_4'], [$this->row($booked[0])['payoutState'], $this->row($booked[0])['payoutReference']]);
    }

    /**
     * The cron stores only into a row still `requested`: one marked by hand
     * between the question and the answer keeps its mark.
     */
    public function testDerCronUeberschreibtKeinenVermerkVonHand(): void
    {
        $this->card->refund = RefundResult::pending('re_3');
        $payout = $this->payout();
        $booked = $payout->refund(1, '20', 'Kulanz', self::NOW);

        $state = $this->createStub(\Webwerkwien\ContaoConshoBundle\Payment\RefundingPaymentHandlerInterface::class);
        $state->method('refundState')->willReturnCallback(function () use ($payout, $booked): RefundResult {
            $payout->markManual($booked[0], self::NOW);

            return RefundResult::failed('abgelehnt');
        });
        $asking = new RefundPayout($this->connection, $this->orders(), $this->methods(), new PaymentHandlers(new ServiceLocator(['card' => static fn (): PaymentHandlerInterface => $state])), $this->logger());
        $asking->checkPending(self::NOW + 3600);

        self::assertSame('manual', $this->row($booked[0])['payoutState']);
    }

    public function testFragtDerCronOhneAntwortBleibtDieZeileFuerDenNaechstenLauf(): void
    {
        $this->card->refund = RefundResult::pending('re_3');
        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        $state = $this->createStub(\Webwerkwien\ContaoConshoBundle\Payment\RefundingPaymentHandlerInterface::class);
        $state->method('refundState')->willThrowException(new \RuntimeException('Zeitüberschreitung'));
        $asking = new RefundPayout($this->connection, $this->orders(), $this->methods(), new PaymentHandlers(new ServiceLocator(['card' => static fn (): PaymentHandlerInterface => $state])), $this->logger());
        $asking->checkPending(self::NOW + 3600);

        self::assertSame(['requested', 're_3'], [$this->row($booked[0])['payoutState'], $this->row($booked[0])['payoutReference']]);
        self::assertStringContainsString('Zeitüberschreitung', implode("\n", $this->log->getArrayCopy()));
    }

    public function testFehlgeschlageneErstattungLaesstSichWiederholen(): void
    {
        $this->card->refund = RefundResult::failed('Guthaben zu niedrig');
        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        $this->card->refund = RefundResult::done('re_2');

        self::assertTrue($this->payout()->retry($booked[0], self::NOW + 60));
        self::assertSame('done', $this->row($booked[0])['payoutState']);
        self::assertCount(2, $this->card->refunds);
    }

    public function testNurFehlgeschlageneWerdenWiederholt(): void
    {
        $this->card->refund = RefundResult::done('re_1');
        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        self::assertFalse($this->payout()->retry($booked[0], self::NOW + 60));
        self::assertCount(1, $this->card->refunds);
    }

    public function testInBearbeitungFragtDerCronNach(): void
    {
        $this->card->refund = RefundResult::pending('re_3');
        $booked = $this->payout()->refund(1, '20', 'Kulanz', self::NOW);

        self::assertSame(['requested', 're_3'], [$this->row($booked[0])['payoutState'], $this->row($booked[0])['payoutReference']]);

        $this->card->state = RefundResult::pending('re_3');
        self::assertSame(1, $this->payout()->checkPending(self::NOW + 3600));
        self::assertSame('requested', $this->row($booked[0])['payoutState']);
        self::assertSame(self::NOW + 3600, (int) $this->row($booked[0])['payoutCheckedAt']);

        $this->card->state = RefundResult::done('re_3');
        $this->payout()->checkPending(self::NOW + 7200);

        self::assertSame(['done', 're_3'], [$this->row($booked[0])['payoutState'], $this->row($booked[0])['payoutReference']]);
        self::assertSame('re_3', $this->card->asked[0]->payoutReference);
        self::assertSame(0, $this->payout()->checkPending(self::NOW + 10800));
    }

    public function testEineZeileWirdNieZweimalAusgezahlt(): void
    {
        $this->card->refund = RefundResult::failed('Guthaben zu niedrig');
        $payout = $this->payout();
        $booked = $payout->book(1, '20', 'Kulanz', self::NOW);

        $payout->payOut($booked, self::NOW);
        $payout->payOut($booked, self::NOW);

        self::assertCount(1, $this->card->refunds);
    }

    public function testEineHaengendeAuszahlungWirdNichtVonSelbstWiederholt(): void
    {
        $booked = $this->payout()->book(1, '20', 'Kulanz', self::NOW);
        // A process that died while asking the provider.
        $this->connection->update('tl_consho_payment', ['payoutState' => 'sending'], ['id' => $booked[0]]);

        $this->payout()->payOut($booked, self::NOW);
        self::assertFalse($this->payout()->retry($booked[0], self::NOW));
        self::assertSame([], $this->card->refunds);

        self::assertTrue($this->payout()->markManual($booked[0], self::NOW));
        self::assertSame('manual', $this->row($booked[0])['payoutState']);
    }

    public function testVonHandErledigtWirdNieMehrGesendet(): void
    {
        $this->card->refund = RefundResult::done('re_1');
        $booked = $this->payout()->book(1, '20', 'Kulanz', self::NOW);

        self::assertTrue($this->payout()->markManual($booked[0], self::NOW));
        $this->payout()->payOut($booked, self::NOW);
        $this->payout()->checkPending(self::NOW + 3600);

        self::assertSame('manual', $this->row($booked[0])['payoutState']);
        self::assertSame([], $this->card->refunds);
        self::assertFalse($this->payout()->markManual($booked[0], self::NOW), 'schon erledigt');
    }

    public function testDerCronSendetWasNachDerBuchungLiegenBlieb(): void
    {
        $this->card->refund = RefundResult::done('re_9');
        // Booked, and the process died before payOut().
        $booked = $this->payout()->book(1, '20', 'Kulanz', self::NOW);

        (new RefundPayoutCron($this->payout(), static fn (): int => self::NOW + 60))();
        $before = $this->row($booked[0]);
        self::assertSame('requested', $before['payoutState'], 'der erste Prozess kann noch dabei sein');

        (new RefundPayoutCron($this->payout(), static fn (): int => self::NOW + 3600))();
        self::assertSame(['done', 're_9'], [$this->row($booked[0])['payoutState'], $this->row($booked[0])['payoutReference']]);
        self::assertCount(1, $this->card->refunds);
    }

    public function testOhneErstattungsfaehigeZahlungsartWirdFehlgeschlagenVermerkt(): void
    {
        $booked = $this->payout()->book(1, '20', 'Kulanz', self::NOW);

        // The module removed between booking and payout.
        $payout = new RefundPayout($this->connection, $this->orders(), $this->methods(), new PaymentHandlers(new ServiceLocator([])), $this->logger());
        $payout->payOut($booked, self::NOW);

        self::assertSame('failed', $this->row($booked[0])['payoutState']);
    }

    private function payout(): RefundPayout
    {
        return new RefundPayout(
            $this->connection,
            $this->orders(),
            $this->methods(),
            new PaymentHandlers(new ServiceLocator([
                'card' => fn (): PaymentHandlerInterface => $this->card,
                'prepayment' => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
            ])),
            $this->logger(),
        );
    }

    private function orders(): PaymentChangeRepositoryInterface
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturn(['id' => '1']);

        return $orders;
    }

    private function methods(): PaymentMethodLookupInterface
    {
        return new class implements PaymentMethodLookupInterface {
            public function byId(int $methodId): PaymentMethod|null
            {
                return match ($methodId) {
                    1 => new PaymentMethod(1, 'card', 'Karte', []),
                    2 => new PaymentMethod(2, 'prepayment', 'Vorkasse', []),
                    default => null,
                };
            }
        };
    }

    private function logger(): AbstractLogger
    {
        return new class($this->log) extends AbstractLogger {
            /**
             * @param \ArrayObject<int, string> $log
             */
            public function __construct(private readonly \ArrayObject $log)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->log[] = (string) $message;
            }
        };
    }

    private function payment(int $id, string $amount, int $receivedAt, int|null $attempt, string $reference): void
    {
        $this->connection->insert('tl_consho_payment', [
            'id' => $id,
            'pid' => 1,
            'amount' => $amount,
            'receivedAt' => $receivedAt,
            'attempt' => $attempt,
            'reference' => $reference,
            'method' => 'Karte',
        ]);
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array{string, int, string}>
     */
    private function rows(array $ids): array
    {
        return array_map(function (int $id): array {
            $row = $this->row($id);

            // SQLite drops the scale MySQL keeps; compare at the column's.
            return [(string) BigDecimal::of((string) $row['amount'])->toScale(4), (int) $row['refundOf'], (string) $row['payoutState']];
        }, $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_payment WHERE id = ?', [$id]);
        self::assertIsArray($row);

        return $row;
    }
}
