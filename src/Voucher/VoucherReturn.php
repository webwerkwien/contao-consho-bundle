<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Money back onto vouchers, and vouchers taken back (block Wertgutscheine,
 * 6.6, WG7, WG11, D1, D2).
 *
 * What a voucher paid for an order is read from its bookings, not from the
 * payment's reference: the anonymisation empties the reference, the
 * bookings keep the order (the voucher is a bearer instrument and carries
 * no person).
 *
 * A refund goes onto the vouchers first, each up to what it paid (§ 14
 * Abs. 1 FAGG, § 357 Abs. 3 BGB: the same means of payment), as a negative
 * payment on the order and a `refund` booking pointing at it. An expired
 * voucher is unlimited again (D1); one still valid keeps its date.
 *
 * Runs inside the caller's transaction (the withdrawal's order lock, the
 * status writer's cancellation); VoucherLedger nests as a savepoint.
 */
class VoucherReturn
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VoucherLedger $ledger,
        private readonly TranslatorInterface|null $translator = null,
    ) {
    }

    /**
     * What each voucher paid for the order and has not had back, in the
     * order they paid.
     *
     * A BLOCKED voucher is left out (review WG-7, S1; Michael, 2026-10-06:
     * „ja, S1 so umsetzen"): money booked back onto it could never be spent
     * again. Its share goes to the payment method instead, and the operator
     * pays it out — nothing is lost.
     *
     * @return array<int, BigDecimal> voucher id => amount, positive only
     */
    public function paidBy(int $orderId): array
    {
        $paid = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT b.pid, SUM(b.amount) AS amount, MIN(b.id) AS first FROM tl_consho_voucher_booking b
               JOIN tl_consho_voucher v ON v.id = b.pid AND v.blocked = 0
              WHERE b.orderId = ? AND b.type IN ('redeem', 'refund')
              GROUP BY b.pid ORDER BY first",
            [$orderId],
        ) as $row) {
            $amount = BigDecimal::of((string) $row['amount'])->negated();

            if ($amount->isPositive()) {
                $paid[(int) $row['pid']] = $amount->toScale(4, RoundingMode::HalfUp);
            }
        }

        return $paid;
    }

    public function paidTotal(int $orderId): BigDecimal
    {
        $total = BigDecimal::zero()->toScale(4);

        foreach ($this->paidBy($orderId) as $amount) {
            $total = $total->plus($amount);
        }

        return $total;
    }

    /**
     * Books $amount back onto the order's vouchers, in the order they paid,
     * each up to what it paid. Never more than they paid altogether.
     *
     * @return list<int> the payment ids
     */
    public function refund(int $orderId, string $amount, int $now, string $reference = '', string $language = ''): array
    {
        $left = BigDecimal::of($amount)->toScale(4, RoundingMode::HalfUp);
        $payments = [];

        foreach ($this->paidBy($orderId) as $voucherId => $paid) {
            if (!$left->isPositive()) {
                break;
            }

            $back = BigDecimal::min($left, $paid);
            $left = $left->minus($back);
            $code = (string) $this->connection->fetchOne('SELECT code FROM tl_consho_voucher WHERE id = ?', [$voucherId]);

            $this->connection->insert('tl_consho_payment', [
                'pid' => $orderId,
                'tstamp' => $now,
                'receivedAt' => $now,
                'amount' => (string) $back->negated(),
                'method' => mb_substr($this->label($code, $language), 0, 64),
                'reference' => 'voucher:'.$voucherId,
                'lockedAt' => $now,
            ]);

            $paymentId = (int) $this->connection->lastInsertId();
            $payments[] = $paymentId;

            $this->ledger->book($voucherId, (string) $back, VoucherBookingType::Refund, $now, $orderId, $paymentId, 0, $reference);

            // D1: an expired voucher is unlimited again; a valid one keeps its date.
            $validUntil = $this->connection->fetchOne('SELECT validUntil FROM tl_consho_voucher WHERE id = ?', [$voucherId]);

            if (VoucherWallet::expired($validUntil, $now)) {
                $this->connection->update('tl_consho_voucher', ['validUntil' => null, 'tstamp' => $now], ['id' => $voucherId]);
            }
        }

        return $payments;
    }

    /**
     * A cancelled order (6.6, D2): what its vouchers paid goes back onto them,
     * and the vouchers it bought are blocked — every one, used in part or
     * not: the money for them goes back, so what is left on them must not
     * stay spendable beside it.
     */
    public function afterCancellation(int $orderId, int $now): void
    {
        /*
         * Under the order's lock (review WG-7, L2), like a withdrawal's
         * refund: a cancellation and a refund at the same moment would
         * otherwise both read what the vouchers paid and both book it back.
         */
        $this->connection->fetchOne('SELECT id FROM tl_consho_order WHERE id = ? FOR UPDATE', [$orderId]);

        $total = $this->paidTotal($orderId);

        if ($total->isPositive()) {
            $language = (string) $this->connection->fetchOne('SELECT language FROM tl_consho_order WHERE id = ?', [$orderId]);
            $this->refund($orderId, (string) $total, $now, '', $language);
        }

        $this->connection->executeStatement(
            "UPDATE tl_consho_voucher SET blocked = 1, tstamp = ? WHERE orderId = ? AND source = 'purchase' AND blocked = 0",
            [$now, $orderId],
        );
    }

    /**
     * A withdrawal of bought vouchers (WG7, review WG-7 S1; Michael,
     * 2026-10-06: „so lange der Gutschein nicht eingelöst ist, kann er
     * widerrufen werden, wurde er eingelöst, wurde damit etwas erworben, was
     * zurück gegeben werden muss"): per withdrawn piece one voucher of the
     * line is taken back — blocked, marked with the withdrawal — and only
     * one NOT REDEEMED, its balance its whole value. What was bought with a
     * redeemed one has to be withdrawn or cancelled first; its refund fills
     * the voucher again (WG11), and then it can go.
     *
     * Pieces without a voucher need none: the line is unpaid and nothing was
     * issued (a line's vouchers are issued together, at the payment), or
     * another withdrawal took it — and the release never issues a withdrawn
     * piece. So only a redeemed voucher can stand in the way.
     *
     * Three things stand in the way, said apart (second review WG-7, L-B,
     * L-C): REDEEMED — an order it paid has not had it back; BLOCKED by the
     * operator (lost, replaced): taking it back would pay out what may already
     * have been handed over again; CHANGED by hand — its balance is not its
     * value although nothing was redeemed. Each the operator decides.
     *
     * The vouchers are read under lock (L-A), in id order like the purchase
     * click: a purchase paying with one at this very moment either finished
     * before — and the voucher reads redeemed — or waits.
     *
     * @param array<int, string> $accepted order item id => accepted quantity, voucher lines only
     *
     * @return list<array{code: string, reason: 'redeemed'|'blocked'|'changed', orders: string}> what is in the way, nothing blocked; [] when done
     */
    public function takeBack(int $orderId, int $withdrawalId, array $accepted, int $now): array
    {
        $plan = [];
        $inTheWay = [];

        foreach ($accepted as $itemId => $quantity) {
            $pieces = BigDecimal::of($quantity)->toScale(0, RoundingMode::Down)->toInt();

            if ($pieces < 1) {
                continue;
            }

            $free = [];

            foreach ($this->connection->fetchAllAssociative(
                "SELECT id, code, faceValue, balance, blocked FROM tl_consho_voucher
                  WHERE orderId = ? AND orderItem = ? AND source = 'purchase' AND withdrawal = 0 ORDER BY id FOR UPDATE",
                [$orderId, $itemId],
            ) as $v) {
                $orders = $this->openRedemptions((int) $v['id']);
                $reason = match (true) {
                    '' !== $orders => 'redeemed',
                    (bool) $v['blocked'] => 'blocked',
                    !BigDecimal::of((string) $v['balance'])->isEqualTo((string) $v['faceValue']) => 'changed',
                    default => null,
                };

                if (null === $reason) {
                    $free[] = (int) $v['id'];
                } else {
                    $inTheWay[(int) $itemId][] = ['code' => VoucherCode::masked((string) $v['code']), 'reason' => $reason, 'orders' => $orders];
                }
            }

            // Short only where something stands in the way; without, the
            // missing pieces have no voucher (the docblock above).
            if (\count($free) >= $pieces || !isset($inTheWay[(int) $itemId])) {
                array_push($plan, ...\array_slice($free, 0, $pieces));
                unset($inTheWay[(int) $itemId]);
            }
        }

        if ([] !== $inTheWay) {
            return array_merge(...array_values($inTheWay));
        }

        foreach ($plan as $id) {
            $this->connection->update('tl_consho_voucher', ['blocked' => 1, 'withdrawal' => $withdrawalId, 'tstamp' => $now], ['id' => $id]);
        }

        return [];
    }

    /**
     * The orders a voucher paid that have not had it back, by number — what
     * the operator has to withdraw or cancel first. '' for none: a
     * redemption booked back in full no longer stands in the way.
     */
    private function openRedemptions(int $voucherId): string
    {
        return implode(', ', array_map(strval(...), $this->connection->fetchFirstColumn(
            "SELECT o.orderNumber FROM tl_consho_voucher_booking b
               JOIN tl_consho_order o ON o.id = b.orderId
              WHERE b.pid = ? AND b.type IN ('redeem', 'refund')
              GROUP BY o.id, o.orderNumber HAVING SUM(b.amount) < 0 ORDER BY o.orderNumber",
            [$voucherId],
        )));
    }

    private function label(string $code, string $language): string
    {
        $masked = VoucherCode::masked($code);

        return $this->translator?->trans('CONSHO.cart.voucher.applied', [$masked], 'contao_default', '' !== $language ? $language : null)
            ?? 'Gift voucher '.$masked;
    }
}
