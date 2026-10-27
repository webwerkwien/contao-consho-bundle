<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Doctrine\DBAL\Connection;

/**
 * "Bestellung zuordnen" for a withdrawal that matched no order (WA, A4).
 *
 * The form stores a declaration whose order number and e-mail matched no order
 * of the shop without an order (WithdrawalRecorder::recordUnassigned); here
 * the operator names the order it belongs to. Only then can it be accepted,
 * rejected or refunded — all of that works on the order's goods and money.
 *
 * ── What changes and what does not ────────────────────────────────────────
 *
 * The order is set, nothing of the declaration: number ("W-00042" — the
 * customer's confirmation names it), moment of receipt, name, address and
 * text stay as they arrived. `afterWindow` is set from the order's window at
 * the moment of RECEIPT, so the mark says what it would have said had the
 * order been found right away. From now on the withdrawal counts like any
 * other: it holds back a digital release while it is open (DigitalRelease),
 * appears on the order page and in the order's list.
 *
 * Only within the shop it came in through: an order of another shop is
 * another seller's contract. The rights check is the caller's (the page
 * checks an update of the order, like every other action there).
 */
final class WithdrawalAssignment
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WithdrawalWindowReader $windows,
    ) {
    }

    /**
     * The order an operator means by this number, in the withdrawal's shop.
     *
     * @return array<string, mixed>|null a row of tl_consho_order
     */
    public function findOrder(int $withdrawalId, string $orderNumber): array|null
    {
        $order = $this->connection->fetchAssociative(
            'SELECT o.* FROM tl_consho_order o JOIN tl_consho_withdrawal w ON w.shop = o.shop WHERE w.id = ? AND o.orderNumber = ?',
            [$withdrawalId, trim($orderNumber)],
        );

        return false === $order ? null : $order;
    }

    public function assign(int $withdrawalId, int $orderId): ProcessingResult
    {
        $withdrawal = $this->connection->fetchAssociative('SELECT pid, shop, number, receivedAt, state FROM tl_consho_withdrawal WHERE id = ?', [$withdrawalId]);

        if (false === $withdrawal) {
            return ProcessingResult::refused('unknown');
        }

        if ((int) $withdrawal['pid'] > 0) {
            return ProcessingResult::refused('assignAlready');
        }

        // Dismissed without an order (S2): it would land on the order as
        // "rejected" without the customer ever being told.
        if ('received' !== $withdrawal['state']) {
            return ProcessingResult::refused('alreadyDecided');
        }

        $orderNumber = $this->connection->fetchOne('SELECT orderNumber FROM tl_consho_order WHERE id = ? AND shop = ?', [$orderId, (int) $withdrawal['shop']]);

        if (false === $orderNumber) {
            return ProcessingResult::refused('assignNotFound');
        }

        $late = $this->windows->of($orderId)?->isLateAt((int) $withdrawal['receivedAt']) ?? false;

        // Guarded on pid = 0: two operators assigning at once — the second
        // finds it done rather than moving it to another order. Not redundant
        // with the check above: that one reads before this write, and between
        // the two another request can assign. No test reaches that gap
        // (SQLite in memory, one connection) — keep the condition anyway
        // (WA).
        $changed = $this->connection->executeStatement(
            "UPDATE tl_consho_withdrawal SET pid = ?, afterWindow = ?, tstamp = ? WHERE id = ? AND pid = 0 AND state = 'received'",
            [$orderId, $late ? 1 : 0, time(), $withdrawalId],
        );

        if (1 !== $changed) {
            return ProcessingResult::refused('assignAlready');
        }

        return ProcessingResult::done($late ? 'assignedLate' : 'assigned', [(string) $withdrawal['number'], (string) $orderNumber]);
    }

    /**
     * "Ohne Bestellung abweisen" (WA): a
     * declaration that belongs to no order — nonsense, a duplicate, an order of
     * another shop — would otherwise stay "received" for good, in the finding
     * and on the dashboard. Rejected with the operator's reason, kept as it
     * arrived (proof of what was declared), and anonymised like the others.
     *
     * No mail: the address was typed, not proven, and the confirmation of
     * receipt already went out (or was held back by the limit).
     */
    public function dismiss(int $withdrawalId, string $reason, int $now): ProcessingResult
    {
        $reason = trim($reason);

        if ('' === $reason) {
            return ProcessingResult::refused('dismissReasonMissing');
        }

        $withdrawal = $this->connection->fetchAssociative('SELECT pid, state, number FROM tl_consho_withdrawal WHERE id = ?', [$withdrawalId]);

        if (false === $withdrawal) {
            return ProcessingResult::refused('unknown');
        }

        if ((int) $withdrawal['pid'] > 0) {
            return ProcessingResult::refused('assignAlready');
        }

        // Guarded like assign(): assigned or decided in between, it stays so.
        $changed = $this->connection->executeStatement(
            "UPDATE tl_consho_withdrawal SET state = 'rejected', reason = ?, decidedAt = ?, tstamp = ? WHERE id = ? AND pid = 0 AND state = 'received'",
            [$reason, $now, $now, $withdrawalId],
        );

        if (1 !== $changed) {
            return ProcessingResult::refused('alreadyDecided');
        }

        return ProcessingResult::done('dismissed', [(string) $withdrawal['number']]);
    }
}
