<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment\Refund;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\ChargebackRow;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEvent;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundResult;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundStateChangedException;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRefundingHandler;

/**
 * Spec 9, N3/P4: a refund made at the provider is recorded or assigned, by
 * its ID; "Von Hand erledigt" offers the provider's refunds.
 */
final class RefundPayoutForeignTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    private FakeRefundingHandler $card;

    /** @var \ArrayObject<int, string> */
    private \ArrayObject $log;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_payment_attempt', 'tl_consho_provider_event', 'tl_consho_withdrawal', 'tl_consho_complaint'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->card = new FakeRefundingHandler();
        $this->log = new \ArrayObject();

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'B-1', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 10, 'pid' => 1, 'tokenHash' => 'h10', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment', ['id' => 101, 'pid' => 1, 'amount' => '50.0000', 'attempt' => 10, 'reference' => 'tr_1', 'method' => 'Karte']);
    }

    public function testRecordingAForeignRefundBooksADoneRowWithItsId(): void
    {
        $event = $this->event('re_x', '20.0000');

        self::assertNull($this->payout()->recordForeign($event, self::NOW, 'k.muster'));

        $row = $this->connection->fetchAssociative("SELECT * FROM tl_consho_payment WHERE method = 'refund'") ?: self::fail('no row');
        self::assertAmount('-20', (string) $row['amount']);
        self::assertSame(101, (int) $row['refundOf']);
        self::assertSame('done', $row['payoutState']);
        self::assertSame('re_x', $row['payoutReference']);

        $resolved = $this->connection->fetchAssociative('SELECT * FROM tl_consho_provider_event WHERE id = ?', [$event]) ?: self::fail('no event');
        self::assertSame('recorded', $resolved['state']);
        self::assertSame((int) $row['id'], (int) $resolved['resolvedRow']);
        self::assertSame('k.muster', $resolved['resolvedBy']);
        self::assertSame(self::NOW, (int) $resolved['resolvedAt']);
    }

    /**
     * Geldflüsse H2: while a withdrawal waits for its refund, the money goes
     * there — nothing recorded, the event stays open. A refunded or rejected
     * withdrawal does not hold it up.
     */
    /**
     * RK-4: the same while a decided rescission or price reduction waits.
     */
    public function testNotRecordedWhileAComplaintWaitsForItsRefund(): void
    {
        $event = $this->event('re_x', '20.0000');
        // Undecided and rejected complaints do not hold it up.
        $this->connection->insert('tl_consho_complaint', ['id' => 1, 'pid' => 1, 'number' => 'B-1-R1', 'remedy' => 'rescission']);
        $this->connection->insert('tl_consho_complaint', ['id' => 2, 'pid' => 1, 'number' => 'B-1-R2', 'remedy' => 'reduction', 'decidedAt' => self::NOW - 20, 'state' => 'rejected']);
        $this->connection->insert('tl_consho_complaint', ['id' => 3, 'pid' => 1, 'number' => 'B-1-R3', 'remedy' => 'reduction', 'decidedAt' => self::NOW - 20, 'state' => 'inProgress']);

        self::assertSame('complaintOpen', $this->payout()->recordForeign($event, self::NOW));
        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));

        $this->connection->update('tl_consho_complaint', ['refundedAt' => self::NOW - 10], ['id' => 3]);

        self::assertNull($this->payout()->recordForeign($event, self::NOW));
    }

    public function testNotRecordedWhileAWithdrawalWaitsForItsRefund(): void
    {
        $event = $this->event('re_x', '20.0000');
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 1, 'number' => 'B-1-W1', 'state' => 'rejected']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 2, 'pid' => 1, 'number' => 'B-1-W2', 'state' => 'accepted']);

        self::assertSame('withdrawalOpen', $this->payout()->recordForeign($event, self::NOW));
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"));
        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));

        $this->connection->update('tl_consho_withdrawal', ['refundedAt' => self::NOW - 10], ['id' => 2]);
        self::assertNull($this->payout()->recordForeign($event, self::NOW));
    }

    public function testRecordingTwiceBooksOnce(): void
    {
        $event = $this->event('re_x', '20.0000');

        $this->payout()->recordForeign($event, self::NOW);

        self::assertSame('stateChanged', $this->payout()->recordForeign($event, self::NOW));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"));
    }

    public function testMoreThanIsLeftOfThePaymentIsRefused(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-40.0000', 'method' => 'refund', 'refundOf' => 101, 'payoutState' => 'done', 'payoutReference' => 're_1']);
        $event = $this->event('re_x', '20.0000');

        self::assertSame('exceeds', $this->payout()->recordForeign($event, self::NOW));
        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));
    }

    public function testOnlyRowsOfTheSameAmountCanBeAssigned(): void
    {
        $match = $this->refundRow('-20.0000', 'manual');
        $this->refundRow('-15.0000', 'manual');
        // Tied to a provider refund already, or settled: not offered.
        $this->refundRow('-20.0000', 'done', 're_1');
        $this->refundRow('-20.0000', 'requested', 're_2');
        $event = $this->event('re_x', '20.0000');

        self::assertSame([$match], array_map(static fn (array $row): int => (int) $row['id'], $this->payout()->assignableRows($event)));
    }

    public function testOpenForeignRefundsAreListedPerOrder(): void
    {
        $open = $this->event('re_a', '2.0000');
        $resolved = $this->event('re_b', '5.0000');
        $this->connection->update('tl_consho_provider_event', ['state' => 'recorded'], ['id' => $resolved]);
        // A chargeback is no refund; another order's refund is not this one's.
        $this->connection->insert('tl_consho_provider_event', ['pid' => 1, 'payment' => 101, 'kind' => 'chargeback', 'reference' => 'cb_1', 'amount' => '50.0000', 'currency' => 'EUR', 'state' => 'open']);
        $this->connection->insert('tl_consho_provider_event', ['pid' => 2, 'payment' => 201, 'kind' => 'refund', 'reference' => 're_c', 'amount' => '3.0000', 'currency' => 'EUR', 'state' => 'open']);

        self::assertSame([['id' => $open, 'reference' => 're_a', 'amount' => '2.0000', 'currency' => 'EUR']], $this->payout()->openForeign(1));
        self::assertSame([], $this->payout()->openForeign(1, 999));
        self::assertSame([['id' => $open, 'reference' => 're_a', 'amount' => '2.0000', 'currency' => 'EUR']], $this->payout()->openForeign(1, 101));
    }

    /**
     * Spec Geldflüsse D3: money the provider already paid back is not
     * offered again — until recorded or assigned, the open event stands for it.
     */
    public function testAnOpenForeignRefundLowersWhatIsRefundable(): void
    {
        $event = $this->event('re_x', '20.0000');
        // A resolved one counts through its row, a chargeback through its own.
        $this->connection->insert('tl_consho_provider_event', ['pid' => 1, 'payment' => 101, 'kind' => 'refund', 'reference' => 're_y', 'amount' => '5.0000', 'currency' => 'EUR', 'state' => 'recorded']);
        $this->connection->insert('tl_consho_provider_event', ['pid' => 1, 'payment' => 101, 'kind' => 'chargeback', 'reference' => 'cb_1', 'amount' => '7.0000', 'currency' => 'EUR', 'state' => 'open']);

        self::assertSame([101 => '30.0000'], $this->payout()->refundable(1));

        // Recording it moves the 20 from the event to a row: still 30, not 10.
        self::assertNull($this->payout()->recordForeign($event, self::NOW));
        self::assertSame([101 => '30.0000'], $this->payout()->refundable(1));
    }

    public function testAssigningTiesTheRowToTheProviderRefund(): void
    {
        $row = $this->refundRow('-20.0000', 'failed');
        $event = $this->event('re_x', '20.0000');

        self::assertNull($this->payout()->assignForeign($event, $row, self::NOW, 'k.muster'));

        self::assertSame(['done', 're_x'], array_values($this->connection->fetchAssociative('SELECT payoutState, payoutReference FROM tl_consho_payment WHERE id = ?', [$row]) ?: []));
        self::assertSame('assigned', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"), 'no new row');
    }

    public function testAnotherAmountCannotBeAssigned(): void
    {
        $row = $this->refundRow('-15.0000', 'manual');
        $event = $this->event('re_x', '20.0000');

        self::assertSame('rowNotAssignable', $this->payout()->assignForeign($event, $row, self::NOW));
        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));
    }

    public function testDoneByHandOffersTheProvidersRefundsNotYetKnown(): void
    {
        $row = $this->refundRow('-20.0000', 'failed');
        $this->refundRow('-20.0000', 'done', 're_known');
        $this->card->listed = [
            ProviderEvent::refund('re_known', '20', 'EUR', 'refunded', self::NOW),
            ProviderEvent::refund('re_a', '20', 'EUR', 'refunded', self::NOW),
            ProviderEvent::refund('re_b', '20', 'EUR', 'processing', self::NOW),
            ProviderEvent::refund('re_pending', '20', 'EUR', 'pending', self::NOW),
            ProviderEvent::refund('re_other', '5', 'EUR', 'refunded', self::NOW),
        ];

        $offered = $this->payout()->providerRefunds($row) ?? self::fail('nothing listed');

        self::assertSame(['re_a', 're_b'], array_map(static fn (ProviderEvent $e): string => $e->reference, $offered));
    }

    public function testWithoutAnAnswerThereIsNoSelection(): void
    {
        $row = $this->refundRow('-20.0000', 'failed');
        $this->card->listed = new \RuntimeException('timeout');

        self::assertNull($this->payout()->providerRefunds($row));
        self::assertStringContainsString('could not be listed', implode("\n", $this->log->getArrayCopy()));
    }

    public function testAssigningAtDoneByHandResolvesAnOpenEventToo(): void
    {
        $row = $this->refundRow('-20.0000', 'requested');
        $event = $this->event('re_x', '20.0000');

        self::assertTrue($this->payout()->assign($row, 're_x', self::NOW));
        self::assertSame('assigned', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));
        self::assertFalse($this->payout()->assign($row, 're_y', self::NOW), 'tied already');
    }

    public function testAProviderIdIsTiedToOneRowOnly(): void
    {
        $first = $this->refundRow('-20.0000', 'failed');
        $second = $this->refundRow('-20.0000', 'failed');

        self::assertTrue($this->payout()->assign($first, 're_x', self::NOW));
        self::assertFalse($this->payout()->assign($second, 're_x', self::NOW));
    }

    public function testAProviderAnswerAfterTheAssignmentIsLoggedAsAPossibleDoublePayment(): void
    {
        $this->card->refund = RefundResult::done('re_late');
        $row = $this->refundRow('-20.0000', 'requested');
        $this->card->meanwhile = function () use ($row): void {
            self::assertTrue($this->payout()->assign($row, 're_x', self::NOW));
        };

        $this->payout()->payOut([$row], self::NOW);

        self::assertSame('re_x', $this->connection->fetchOne('SELECT payoutReference FROM tl_consho_payment WHERE id = ?', [$row]));
        self::assertStringContainsString('double payment', implode("\n", $this->log->getArrayCopy()));
    }

    public function testAnOwnRefundReportedBeforeItsAnswerResolvesTheEvent(): void
    {
        $this->card->refund = RefundResult::done('re_x');
        $row = $this->refundRow('-20.0000', 'requested');
        $event = 0;
        // The notification of a module that does not mark its own refunds.
        $this->card->meanwhile = function () use (&$event): void {
            $event = $this->event('re_x', '20.0000');
        };

        $this->payout()->payOut([$row], self::NOW);

        $resolved = $this->connection->fetchAssociative('SELECT state, resolvedRow FROM tl_consho_provider_event WHERE id = ?', [$event]) ?: self::fail('no event');
        self::assertSame('assigned', $resolved['state']);
        self::assertSame($row, (int) $resolved['resolvedRow']);
        self::assertSame('stateChanged', $this->payout()->recordForeign($event, self::NOW));
        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"));
    }

    public function testAPendingOwnRefundResolvesTheEventWhenAskedAgain(): void
    {
        $this->card->state = RefundResult::done('re_x');
        $row = $this->refundRow('-20.0000', 'requested', 're_x');
        $event = $this->event('re_x', '20.0000');

        $this->payout()->checkPending(self::NOW);

        self::assertSame('assigned', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));
        self::assertSame($row, (int) $this->connection->fetchOne('SELECT resolvedRow FROM tl_consho_provider_event WHERE id = ?', [$event]));
    }

    public function testARefundAlreadyOnAnOwnRowIsNotRecordedAgain(): void
    {
        $row = $this->refundRow('-20.0000', 'done', 're_x');
        $event = $this->event('re_x', '20.0000');

        self::assertSame('stateChanged', $this->payout()->recordForeign($event, self::NOW, 'k.muster'));

        self::assertSame(1, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"));
        self::assertSame(['assigned', $row], (static fn (array $e): array => [$e['state'], (int) $e['resolvedRow']])($this->connection->fetchAssociative('SELECT state, resolvedRow FROM tl_consho_provider_event WHERE id = ?', [$event]) ?: []));
    }

    /**
     * The own refund is booked already: an open withdrawal of the order does
     * not hold up resolving its event.
     */
    public function testAnOwnRefundIsResolvedEvenWhileAWithdrawalIsOpen(): void
    {
        $row = $this->refundRow('-20.0000', 'done', 're_x');
        $event = $this->event('re_x', '20.0000');
        $this->connection->insert('tl_consho_withdrawal', ['id' => 2, 'pid' => 1, 'number' => 'B-1-W2', 'state' => 'received']);

        self::assertSame('stateChanged', $this->payout()->recordForeign($event, self::NOW));
        self::assertSame(['assigned', $row], (static fn (array $e): array => [$e['state'], (int) $e['resolvedRow']])($this->connection->fetchAssociative('SELECT state, resolvedRow FROM tl_consho_provider_event WHERE id = ?', [$event]) ?: []));
    }

    public function testAnOwnRowOfAnotherOrderDoesNotResolveTheEvent(): void
    {
        $this->card->refund = RefundResult::done('re_x');
        $row = $this->refundRow('-20.0000', 'requested');
        $this->connection->insert('tl_consho_order', ['id' => 2, 'orderNumber' => 'B-2', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_provider_event', ['pid' => 2, 'payment' => 0, 'kind' => 'refund', 'reference' => 're_x', 'amount' => '20.0000', 'currency' => 'EUR', 'state' => 'open']);
        $other = (int) $this->connection->lastInsertId();

        $this->payout()->payOut([$row], self::NOW);

        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$other]));
    }

    /**
     * Geldflüsse T3: one refund, its ways in order — the refund already made
     * at the provider, then the provider for the rest.
     */
    public function testEineErstattungNimmtDieGewaehlteFremdeErstattungZuerst(): void
    {
        $this->card->refund = RefundResult::done('re_new');
        $event = $this->event('re_x', '20.0000');

        $rows = $this->payout()->refund(1, '50', 'Widerruf B-1', self::NOW, null, [$event], 'k.muster');

        self::assertCount(2, $rows);
        $foreign = $this->connection->fetchAssociative('SELECT * FROM tl_consho_payment WHERE id = ?', [$rows[0]]) ?: self::fail('no row');
        self::assertSame(['Widerruf B-1', 'done', 're_x', 101], [$foreign['reference'], $foreign['payoutState'], $foreign['payoutReference'], (int) $foreign['refundOf']]);
        self::assertAmount('-20', (string) $foreign['amount']);

        $resolved = $this->connection->fetchAssociative('SELECT * FROM tl_consho_provider_event WHERE id = ?', [$event]) ?: self::fail('no event');
        self::assertSame(['assigned', $rows[0], 'k.muster'], [$resolved['state'], (int) $resolved['resolvedRow'], $resolved['resolvedBy']]);

        // Only the rest went to the provider, once.
        self::assertCount(1, $this->card->refunds);
        self::assertSame('30.0000', $this->card->refunds[0]->amount);
        self::assertSame([], $this->payout()->refundable(1));
    }

    public function testOhneDieFremdeErstattungBleibtNurDerRest(): void
    {
        $this->event('re_x', '20.0000');

        self::assertSame([], $this->payout()->refund(1, '50', 'Widerruf', self::NOW), 'only 30 are left at the provider');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"));
    }

    /**
     * A chosen foreign refund that no longer fits stops the whole booking —
     * its money must not go to the provider instead.
     */
    public function testEineUnpassendeFremdeErstattungBuchtGarNichts(): void
    {
        $event = $this->event('re_x', '20.0000');
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 11, 'pid' => 1, 'tokenHash' => 'h11', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment', ['id' => 102, 'pid' => 1, 'amount' => '40.0000', 'attempt' => 11, 'reference' => 'tr_2', 'method' => 'Karte']);

        self::assertSame([], $this->payout()->refund(1, '10', 'zu wenig', self::NOW, null, [$event]), 'more than the refund');
        self::assertSame([], $this->payout()->refund(1, '30', 'andere Zahlung', self::NOW, 102, [$event]), 'of another payment');
        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"));
        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));
        self::assertSame([], $this->card->refunds);
    }

    public function testOhneAuszahlungFremdZuerstDerRestVonHand(): void
    {
        $event = $this->event('re_x', '20.0000');

        $rows = $this->payout()->book(1, '35', 'Widerruf', self::NOW, false, null, [$event]);

        self::assertSame(['done', 'manual'], array_map(fn (int $id): string => (string) $this->connection->fetchOne('SELECT payoutState FROM tl_consho_payment WHERE id = ?', [$id]), $rows));
        self::assertAmount('-15', (string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE id = ?', [$rows[1]]));
    }

    /**
     * book() itself, as the withdrawal calls it under its lock: a chosen
     * foreign refund that does not fit throws before anything is written —
     * the caller rolls back.
     */
    public function testBookWirftBeiUnpassenderFremderErstattung(): void
    {
        $event = $this->event('re_x', '30.0000');

        try {
            $this->payout()->book(1, '20', 'Widerruf', self::NOW, false, null, [$event]);
            self::fail('expected RefundStateChangedException');
        } catch (RefundStateChangedException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_payment WHERE method = 'refund'"));
        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = ?', [$event]));
    }

    /**
     * By hand takes what is left of a payment after a chargeback (spec 9.2);
     * the bank's reversal gives it back. Neither row counts as a payment of
     * its own.
     */
    public function testVonHandRechnetRueckbuchungUndStornoMit(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-20.0000', 'method' => ChargebackRow::METHOD, 'refundOf' => 101, 'payoutReference' => 'chb_1']);

        $rows = $this->payout()->book(1, '40', 'Widerruf', self::NOW, false);
        self::assertSame([101, 0], array_map(fn (int $id): int => (int) $this->connection->fetchOne('SELECT refundOf FROM tl_consho_payment WHERE id = ?', [$id]), $rows));
        self::assertAmount('-30', (string) $this->connection->fetchOne('SELECT amount FROM tl_consho_payment WHERE id = ?', [$rows[0]]));

        $this->connection->delete('tl_consho_payment', ['method' => 'refund']);
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '20.0000', 'method' => ChargebackRow::REVERSAL, 'refundOf' => 101, 'payoutReference' => 'chb_1']);

        $rows = $this->payout()->book(1, '40', 'Widerruf', self::NOW, false);
        self::assertCount(1, $rows, 'reversed: the whole 50 are there again');
        self::assertSame(101, (int) $this->connection->fetchOne('SELECT refundOf FROM tl_consho_payment WHERE id = ?', [$rows[0]]));
    }

    private function event(string $reference, string $amount): int
    {
        $this->connection->insert('tl_consho_provider_event', ['pid' => 1, 'payment' => 101, 'kind' => 'refund', 'reference' => $reference, 'amount' => $amount, 'currency' => 'EUR', 'state' => 'open']);

        return (int) $this->connection->lastInsertId();
    }

    private function refundRow(string $amount, string $state, string $payoutReference = ''): int
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => $amount, 'method' => 'refund', 'refundOf' => 101, 'payoutState' => $state, 'payoutReference' => $payoutReference, 'tstamp' => self::NOW - 7200]);

        return (int) $this->connection->lastInsertId();
    }

    private function payout(): RefundPayout
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturn(['id' => '1']);

        $methods = new class implements PaymentMethodLookupInterface {
            public function byId(int $methodId): PaymentMethod|null
            {
                return 1 === $methodId ? new PaymentMethod(1, 'card', 'Karte', []) : null;
            }
        };

        $logger = new class($this->log) extends AbstractLogger {
            /** @param \ArrayObject<int, string> $log */
            public function __construct(private readonly \ArrayObject $log)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->log[] = (string) $message;
            }
        };

        return new RefundPayout($this->connection, $orders, $methods, new PaymentHandlers(new ServiceLocator([
            'card' => fn (): PaymentHandlerInterface => $this->card,
            'prepayment' => static fn (): PaymentHandlerInterface => new PrepaymentHandler(),
        ])), $logger);
    }

    private static function assertAmount(string $expected, string $actual): void
    {
        self::assertTrue(BigDecimal::of($expected)->isEqualTo($actual), \sprintf('%s expected, got %s', $expected, $actual));
    }
}
