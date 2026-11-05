<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Payment\ChargebackRow;
use Webwerkwien\ContaoConshoBundle\Payment\CorrectionRow;

/**
 * Every movement of money of one order, and its balance (block Geldflüsse,
 * 3.6) — what the page "Geldflüsse" shows.
 *
 * The rows of tl_consho_payment are the money; the provider's events only
 * explain some of them. A chargeback, and a foreign refund once recorded or
 * assigned, carry the event's ID in `payoutReference`, so the event is
 * attached to its row. An event without a row — an open foreign refund — is
 * a movement of its own: the money is back with the customer, but not yet
 * in the books.
 *
 * The balance takes the claim and the state from OrderBalanceReader, like
 * every other place; the parts (received, paid back, charged back) are summed
 * here with corrections netted into the row they correct (CorrectionRow).
 */
final class MoneyFlows
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    /**
     * @return array{
     *     balance: array{claim: string, received: string, refunded: string, chargedBack: string, outside: string, outstanding: string, tooMuch: string},
     *     cancelled: bool,
     *     movements: list<array<string, mixed>>
     * }|null
     */
    public function of(int $orderId): array|null
    {
        $balance = $this->balances->of($orderId);

        if (null === $balance) {
            return null;
        }

        $rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_payment WHERE pid = ? ORDER BY receivedAt, id', [$orderId]);
        $events = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_provider_event WHERE pid = ? ORDER BY occurredAt, id', [$orderId]);
        $withdrawals = $this->connection->fetchAllAssociative('SELECT id, number, refundDocument FROM tl_consho_withdrawal WHERE pid = ?', [$orderId]);

        $byReference = [];

        foreach ($events as $event) {
            $byReference[(string) $event['reference']] = $event;
        }

        $byNumber = [];

        foreach ($withdrawals as $withdrawal) {
            $byNumber[(string) $withdrawal['number']] = $withdrawal;
        }

        $received = BigDecimal::zero();
        $refunded = BigDecimal::zero();
        $chargedBack = BigDecimal::zero();
        $plain = [];

        foreach ($rows as $row) {
            if (ChargebackRow::is((string) $row['method'])) {
                $chargedBack = $chargedBack->plus((string) $row['amount']);
            } else {
                $plain[] = ['id' => (int) $row['id'], 'amount' => (string) $row['amount'], 'method' => (string) $row['method'], 'refundOf' => (int) $row['refundOf']];
            }
        }

        foreach (CorrectionRow::net($plain) as $amount) {
            $value = BigDecimal::of($amount);
            $value->isNegative() ? $refunded = $refunded->minus($value) : $received = $received->plus($value);
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        // A refund against a payment locks it as well (LockedRecords).
        $hasRefund = array_fill_keys(array_map(static fn (array $row): int => (int) $row['refundOf'], $rows), true);
        $movements = [];
        $booked = [];

        foreach ($rows as $row) {
            $method = (string) $row['method'];
            $amount = BigDecimal::of((string) $row['amount']);
            $event = '' !== (string) $row['payoutReference'] ? ($byReference[(string) $row['payoutReference']] ?? null) : null;

            if (null !== $event) {
                $booked[(int) $event['id']] = true;
            }

            $withdrawal = 'refund' === $method ? ($byNumber[(string) $row['reference']] ?? null) : null;

            $movements[] = [
                'source' => 'payment',
                'id' => (int) $row['id'],
                'kind' => match (true) {
                    ChargebackRow::METHOD === $method => 'chargeback',
                    ChargebackRow::REVERSAL === $method => 'chargebackReversal',
                    CorrectionRow::is($method) => 'correction',
                    $amount->isNegative() => 'refund',
                    default => 'payment',
                },
                'at' => (int) $row['receivedAt'],
                'amount' => (string) $amount->toScale(4),
                'method' => $method,
                'reference' => (string) $row['reference'],
                'payoutState' => (string) $row['payoutState'],
                'payoutReference' => (string) $row['payoutReference'],
                'locked' => null !== $row['lockedAt'] || isset($hasRefund[(int) $row['id']]),
                // The payment it pays back, takes back or corrects — if it is
                // one of this order's.
                'refundOf' => \in_array((int) $row['refundOf'], $ids, true) ? (int) $row['refundOf'] : 0,
                // A refund booked by a withdrawal carries its number (GF-2).
                'withdrawal' => null === $withdrawal ? null : ['id' => (int) $withdrawal['id'], 'number' => (string) $withdrawal['number'], 'document' => (int) $withdrawal['refundDocument']],
                'event' => $event,
            ];
        }

        // Events without a row: money moved at the provider, not in the books.
        foreach ($events as $event) {
            if (isset($booked[(int) $event['id']]) || 'refund' !== $event['kind'] || 'open' !== $event['state']) {
                continue;
            }

            $movements[] = [
                'source' => 'event',
                'id' => (int) $event['id'],
                'kind' => 'foreignRefund',
                'at' => (int) $event['occurredAt'],
                'amount' => (string) BigDecimal::of((string) $event['amount'])->negated()->toScale(4),
                'method' => '',
                'reference' => (string) $event['reference'],
                'payoutState' => '',
                'payoutReference' => (string) $event['reference'],
                'locked' => false,
                'refundOf' => \in_array((int) $event['payment'], $ids, true) ? (int) $event['payment'] : 0,
                'withdrawal' => null,
                'event' => $event,
            ];
        }

        // In time; on the same second, rows before events, then by id.
        usort($movements, static fn (array $a, array $b): int => [$a['at'], 'event' === $a['source'], $a['id']] <=> [$b['at'], 'event' === $b['source'], $b['id']]);

        $outside = BigDecimal::zero();

        foreach ($movements as $movement) {
            if ('foreignRefund' === $movement['kind']) {
                $outside = $outside->minus($movement['amount']);
            }
        }

        $outstanding = BigDecimal::of($balance->outstanding());
        $tooMuch = $outstanding->negated()->minus($outside);

        return [
            'balance' => [
                'claim' => (string) BigDecimal::of($balance->claim)->toScale(4),
                'received' => (string) $received->toScale(4),
                'refunded' => (string) $refunded->toScale(4),
                'chargedBack' => (string) $chargedBack->negated()->toScale(4),
                'outside' => (string) $outside->toScale(4),
                'outstanding' => (string) BigDecimal::max(BigDecimal::zero(), $outstanding)->toScale(4),
                'tooMuch' => (string) BigDecimal::max(BigDecimal::zero(), $tooMuch)->toScale(4),
            ],
            'cancelled' => $balance->cancelled,
            'movements' => $movements,
        ];
    }
}
