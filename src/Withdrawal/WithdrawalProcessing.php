<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementRevocations;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\Refund\CreditLines;
use Webwerkwien\ContaoConshoBundle\Order\Refund\OrderRefund;
use Webwerkwien\ContaoConshoBundle\Order\Refund\RefundOccasion;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPurchaseCancellation;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;

/**
 * The operator's side of a withdrawal (D-3, spec 6.3, decisions F1–F10):
 * accept or reject, record the return, book the refund.
 *
 * ── The declaration is never touched ──────────────────────────────────────
 *
 * What the customer declared stays as it arrived — it is the proof of the
 * confirmation of receipt. Everything here writes fields of its own:
 * `acceptedQuantity` and `returnedQuantity` beside the declared quantity,
 * rows with `declared = 0` where the customer named no items (the whole
 * order, or a part in free text).
 *
 * ── Every action under the order lock ─────────────────────────────────────
 *
 * Like the delivery report (D-1): the order row is locked first, then the
 * withdrawal read under it. Two operators clicking "Erstattung buchen" at
 * once wait for each other, and the second finds the refund booked.
 *
 * ── The refund (F1, F3, F4) ───────────────────────────────────────────────
 *
 * One transaction: the negative payment, the document, the withdrawal's
 * refund fields. After the commit: archive, mail, status. The document:
 *
 *  - everything refunded in this one step (all goods, all delivery costs, no
 *    earlier refund) → the invoice is CANCELLED, and the refund is the grand
 *    total — payment fees included, everything the customer paid;
 *  - otherwise an INVOICE CORRECTION with the refunded lines;
 *  - no invoice yet (it is issued on shipment) → no document; the claim
 *    simply falls.
 *
 * The payment is what the customer actually paid over what is still owed —
 * never money that never came in. An unpaid order whose goods are withdrawn
 * gets its claim lowered, not a refund.
 */
class WithdrawalProcessing
{
    // The credit lines and their total, shared with the complaint (RK-3).
    // Built here, not injected: the constructor stays as callers know it.
    private readonly CreditLines $creditLines;

    // The document and the money of the refund, shared the same way.
    private readonly OrderRefund $refunds;

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        // These five only build the shared credit lines and refund below.
        DocumentRepositoryInterface $documentRows,
        DocumentIssuerInterface $documents,
        DocumentArchiverInterface $archiver,
        DocumentPayloadBuilder $payloads,
        OrderBalanceReaderInterface $balances,
        private readonly OrderStatusAdvancer $statuses,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        private readonly MoneyFormatter $money,
        // The payment method's part, booked against the payments it pays
        // back: through the provider, or by hand.
        private readonly RefundPayout $payouts,
        private readonly SubscriptionPurchaseCancellation|null $subscriptions = null,
        private readonly ValueCompensation|null $compensations = null,
        // Block G (G9): returned goods back into stock. Optional for the
        // tests built by hand; the container wires it.
        private readonly StockReturn|null $stock = null,
        // Block Wertgutscheine (WG-6): refunds onto vouchers first (WG11),
        // bought vouchers taken back (WG7). Optional like the ones above.
        private readonly VoucherReturn|null $vouchers = null,
        // Nachlese E6: delivery modules told of revoked entitlements.
        private readonly EntitlementRevocations|null $revocations = null,
    ) {
        $this->creditLines = new CreditLines($connection, $payloads);
        $this->refunds = new OrderRefund($documentRows, $documents, $archiver, $balances, $payouts, $this->creditLines, $vouchers);
    }

    /**
     * Accept, with the quantities the operator confirms per goods line.
     *
     * @param array<int, string> $quantities order item id => accepted quantity
     */
    public function accept(int $withdrawalId, array $quantities, int $now): ProcessingResult
    {
        return $this->locked($withdrawalId, function (array $withdrawal, array $order) use ($quantities, $now): ProcessingResult {
            if ('received' !== $withdrawal['state']) {
                return ProcessingResult::refused('alreadyDecided');
            }

            $goods = $this->goods((int) $order['id']);
            $elsewhere = $this->acceptedElsewhere((int) $order['id'], (int) $withdrawal['id']);
            $accepted = [];

            foreach ($quantities as $itemId => $quantity) {
                try {
                    $amount = BigDecimal::of('' !== trim($quantity) ? str_replace(',', '.', trim($quantity)) : '0');
                } catch (MathException) {
                    return ProcessingResult::refused('invalidQuantity');
                }

                if ($amount->isZero()) {
                    continue;
                }

                if (!isset($goods[$itemId]) || $amount->isNegative()) {
                    return ProcessingResult::refused('invalidQuantity');
                }

                $left = BigDecimal::of($goods[$itemId]['quantity'])->minus($elsewhere[$itemId] ?? '0');

                if ($amount->isGreaterThan($left)) {
                    return ProcessingResult::refused('tooMuch', [$goods[$itemId]['productName'], (string) $left->strippedOfTrailingZeros()], [1 => ProcessingResult::QUANTITY]);
                }

                // A subscription line goes only whole (Abo-8, P77, A11).
                if ((int) ($goods[$itemId]['subscriptionRule'] ?? 0) > 0 && !$amount->isEqualTo($left)) {
                    return ProcessingResult::refused('wholeOnly', [$goods[$itemId]['productName'], (string) $left->strippedOfTrailingZeros()]);
                }

                $accepted[$itemId] = (string) $amount;
            }

            if ([] === $accepted) {
                return ProcessingResult::refused('nothingAccepted');
            }

            $declared = $this->connection->fetchAllKeyValue('SELECT orderItem, id FROM tl_consho_withdrawal_item WHERE pid = ? AND declared = 1', [$withdrawal['id']]);

            foreach ($declared as $itemId => $rowId) {
                $this->connection->update('tl_consho_withdrawal_item', ['acceptedQuantity' => $accepted[(int) $itemId] ?? '0', 'tstamp' => $now], ['id' => $rowId]);
            }

            foreach ($accepted as $itemId => $quantity) {
                if (!isset($declared[$itemId])) {
                    $this->connection->insert('tl_consho_withdrawal_item', [
                        'pid' => $withdrawal['id'],
                        'tstamp' => $now,
                        'orderItem' => $itemId,
                        'quantity' => '0',
                        'declared' => 0,
                        'acceptedQuantity' => $quantity,
                    ]);
                }
            }

            /*
             * The delivery costs proposed (F1): all that are left where this
             * acceptance completes the withdrawal of every goods line, else
             * nothing — the law does not say what a partial withdrawal owes
             * (§ 357 Abs. 2 BGB, § 14 FAGG), so the operator decides.
             */
            $complete = true;

            foreach ($goods as $itemId => $good) {
                $total = BigDecimal::of($elsewhere[$itemId] ?? '0')->plus($accepted[$itemId] ?? '0');

                if ($total->isLessThan($good['quantity'])) {
                    $complete = false;
                }
            }

            $this->connection->update('tl_consho_withdrawal', [
                'state' => 'accepted',
                'decidedAt' => $now,
                'refundShipping' => $complete ? (string) $this->shippingLeft((int) $order['id']) : '0',
                'tstamp' => $now,
            ], ['id' => $withdrawal['id']]);

            /*
             * WG7, WG-7: the bought vouchers withdrawn are taken
             * back, one per piece — only ones not redeemed. A redeemed one
             * bought something that has to go back first; the acceptance is
             * refused (and rolled back) with the orders to see to.
             */
            $redeemed = $this->vouchers?->takeBack((int) $order['id'], (int) $withdrawal['id'], array_filter(
                $accepted,
                static fn (int $itemId): bool => ProductKind::Voucher->value === ($goods[$itemId]['kind'] ?? ''),
                ARRAY_FILTER_USE_KEY,
            ), $now) ?? [];

            // One message per reason, the weightiest first.
            foreach (['redeemed' => 'voucherRedeemed', 'blocked' => 'voucherBlocked', 'changed' => 'voucherChangedByHand'] as $reason => $key) {
                $these = array_filter($redeemed, static fn (array $v): bool => $reason === $v['reason']);

                if ([] !== $these) {
                    return ProcessingResult::refused($key, [implode('; ', array_map(static fn (array $v): string => '' !== $v['orders'] ? $v['code'].' ('.$v['orders'].')' : $v['code'], $these))]);
                }
            }

            // Abo-8, P79: the value compensation proposed for a service —
            // worked out before the subscriptions end, while the release and
            // the period are still the purchase's.
            $this->connection->update('tl_consho_withdrawal', [
                'valueCompensation' => ($this->compensations ?? new ValueCompensation($this->connection))->proposal((int) $withdrawal['id'])['amount'],
            ], ['id' => $withdrawal['id']]);

            // Abo-8, P78: the subscriptions of the withdrawn lines end now, in
            // this transaction; a renewal gives the licence back.
            $this->subscriptions?->afterWithdrawal((int) $order['id'], array_keys($accepted), $now);

            // DL9: a one-time service withdrawn in full is no longer usable.
            $this->revokeServices((int) $order['id'], array_keys(array_filter(
                $accepted,
                static fn (string $quantity, int $itemId): bool => isset($goods[$itemId]) && BigDecimal::of($elsewhere[$itemId] ?? '0')->plus($quantity)->isGreaterThanOrEqualTo($goods[$itemId]['quantity']),
                ARRAY_FILTER_USE_BOTH,
            )), $now);

            // Block G: a key line withdrawn before its delivery
            // no longer waits for a code; the product page has to know.
            $this->stock?->keysFreed((int) $order['id']);

            return ProcessingResult::done('accepted', [(string) $withdrawal['number']]);
        });
    }

    /**
     * Reject, with the reason that goes to the customer verbatim (F6).
     */
    public function reject(int $withdrawalId, string $reason, int $now): ProcessingResult
    {
        $reason = trim($reason);

        if ('' === $reason) {
            return ProcessingResult::refused('reasonMissing');
        }

        $result = $this->locked($withdrawalId, function (array $withdrawal) use ($reason, $now): ProcessingResult {
            if ('received' !== $withdrawal['state']) {
                return ProcessingResult::refused('alreadyDecided');
            }

            $this->connection->update('tl_consho_withdrawal', ['state' => 'rejected', 'decidedAt' => $now, 'reason' => $reason, 'tstamp' => $now], ['id' => $withdrawal['id']]);

            return ProcessingResult::done('rejected', [(string) $withdrawal['number']]);
        });

        if ($result->done) {
            $withdrawal = $this->withdrawal($withdrawalId);
            $order = $this->orders->order((int) $withdrawal['pid']);

            if (null !== $order && !$this->mail->withdrawalRejected((int) $order['id'], (string) $withdrawal['number'], $reason, $this->links->forMail($order) ?? '')) {
                return ProcessingResult::done('rejectedNoMail', [(string) $withdrawal['number']]);
            }
        }

        return $result;
    }

    /**
     * The goods that came back (F8): the date and per line how many.
     *
     * @param array<int, string> $quantities withdrawal item id => returned quantity
     * @param array<int, bool>   $restock    withdrawal item id => back into stock (G9); a line
     *                                       not named keeps its last choice, on by default
     */
    public function recordReturn(int $withdrawalId, int $receivedAt, array $quantities, int $now, array $restock = [], int $user = 0): ProcessingResult
    {
        return $this->locked($withdrawalId, function (array $withdrawal) use ($receivedAt, $quantities, $now, $restock, $user): ProcessingResult {
            if ('accepted' !== $withdrawal['state']) {
                return ProcessingResult::refused('notAccepted');
            }

            $items = $this->connection->fetchAllAssociativeIndexed('SELECT id, acceptedQuantity FROM tl_consho_withdrawal_item WHERE pid = ?', [$withdrawal['id']]);

            foreach ($quantities as $rowId => $quantity) {
                try {
                    $amount = BigDecimal::of('' !== trim($quantity) ? str_replace(',', '.', trim($quantity)) : '0');
                } catch (MathException) {
                    return ProcessingResult::refused('invalidQuantity');
                }

                if (!isset($items[$rowId]) || $amount->isNegative() || $amount->isGreaterThan((string) $items[$rowId]['acceptedQuantity'])) {
                    return ProcessingResult::refused('invalidQuantity');
                }

                $this->connection->update('tl_consho_withdrawal_item', ['returnedQuantity' => (string) $amount, 'tstamp' => $now], ['id' => $rowId]);
            }

            // Block G (G9): in this transaction, after the quantities — a
            // correction books the difference, never the whole line again.
            if (null !== $this->stock) {
                foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_withdrawal_item WHERE pid = ?', [$withdrawal['id']]) as $rowId) {
                    $rowId = (int) $rowId;
                    $tick = $restock[$rowId] ?? (bool) $this->connection->fetchOne('SELECT restock FROM tl_consho_withdrawal_item WHERE id = ?', [$rowId]);
                    $this->stock->forWithdrawalItem($rowId, $tick, $now, $user);
                }
            }

            // An empty date keeps the one recorded (D-3): whoever
            // only corrects a quantity must not erase when the goods came.
            $this->connection->update('tl_consho_withdrawal', ['returnReceivedAt' => $receivedAt > 0 ? $receivedAt : $withdrawal['returnReceivedAt'], 'tstamp' => $now], ['id' => $withdrawal['id']]);

            return ProcessingResult::done('returnRecorded', [(string) $withdrawal['number']]);
        });
    }

    /**
     * Book the refund (F3, F4): the negative payment, the document, the mail.
     *
     * @param string      $shipping     the delivery costs to refund with it, gross
     * @param string|null $compensation the value compensation kept back for a
     *                                  service (P79), gross; '' is 0, null keeps the proposal
     * @param bool $throughProvider the payment method's part through its provider
     *                              (block Zahlung Mollie, R2): booked here, paid out
     *                              after the commit (R1); what no provider takes, by hand
     * @param string|null $confirmed the amount through the provider the operator
     *                               confirmed (R5). Without it, a refund that would pay
     *                               out through a provider books nothing and answers
     *                               `confirmPayout` with that amount and the rest by
     *                               hand; with it, a different amount under the lock
     *                               books nothing either (`payoutChanged`)
     * @param list<int> $foreign refunds already made at the provider that are part
     *                           of this one (Geldflüsse T5): booked first, so the
     *                           provider pays only the rest
     * @param string $by the back-end user who booked it, recorded at the
     *                   provider's refunds taken over
     */
    public function refund(int $withdrawalId, string $shipping, int $now, string|null $compensation = null, bool $throughProvider = false, string|null $confirmed = null, array $foreign = [], string $by = ''): ProcessingResult
    {
        $booked = null;

        $result = $this->locked($withdrawalId, function (array $withdrawal, array $order) use ($shipping, $now, $compensation, $throughProvider, $confirmed, $foreign, $by, &$booked): ProcessingResult {
            if ('accepted' !== $withdrawal['state']) {
                return ProcessingResult::refused('notAccepted');
            }

            if (null !== $withdrawal['refundedAt']) {
                return ProcessingResult::refused('alreadyRefunded');
            }

            $orderId = (int) $order['id'];
            $shippingLeft = $this->shippingLeft($orderId);
            $currency = (string) ($order['currency'] ?? '');
            $digits = '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;

            // The message names the most in both cases: it has a placeholder,
            // and sprintf() throws on a missing argument.
            $shippingRefused = ProcessingResult::refused('invalidShipping', [(string) $shippingLeft->toScale($digits, RoundingMode::HalfUp)], [ProcessingResult::MONEY]);

            try {
                $shippingAmount = BigDecimal::of('' !== trim($shipping) ? str_replace(',', '.', trim($shipping)) : '0');
            } catch (MathException) {
                return $shippingRefused;
            }

            if ($shippingAmount->isNegative() || $shippingAmount->isGreaterThan($shippingLeft)) {
                return $shippingRefused;
            }

            /*
             * The value compensation (Abo-8, P79): kept back from the credit
             * of the service lines, at most what they credit. Kept back at
             * all, the invoice is corrected, not cancelled — something of it
             * stays owed.
             */
            try {
                // An emptied field keeps nothing back, like the delivery share;
                // only a field never shown (null) keeps the proposal.
                $kept = BigDecimal::of(null === $compensation
                    ? (string) ($withdrawal['valueCompensation'] ?? '0')
                    : ('' !== trim($compensation) ? str_replace(',', '.', trim($compensation)) : '0'));
            } catch (MathException) {
                return ProcessingResult::refused('invalidCompensation', ['0'], [ProcessingResult::MONEY]);
            }

            $kept = $kept->toScale($digits, RoundingMode::HalfUp);
            $keepable = $this->serviceCredit($orderId, (int) $withdrawal['id']);

            if ($kept->isNegative() || $kept->isGreaterThan($keepable)) {
                return ProcessingResult::refused('invalidCompensation', [(string) $keepable->toScale($digits, RoundingMode::HalfUp)], [ProcessingResult::MONEY]);
            }

            $lines = $this->lines($orderId, (int) $withdrawal['id'], $shippingAmount, $kept);
            $everything = $kept->isZero() && $this->everything($orderId, (int) $withdrawal['id'], $shippingAmount, $shippingLeft);

            // The document and the money (RK-3): shared with the complaint.
            $booking = $this->refunds->book(
                new RefundOccasion($order, (string) $withdrawal['number'], $lines, $everything, $this->goodsCredit($orderId, (int) $withdrawal['id'])),
                $now,
                $throughProvider,
                $confirmed,
                $foreign,
                $by,
            );

            if ($booking instanceof ProcessingResult) {
                return $booking;
            }

            $this->connection->update('tl_consho_withdrawal', [
                'refundShipping' => (string) $shippingAmount,
                'valueCompensation' => (string) $kept,
                'refundAmount' => $booking->amount,
                'refundedAt' => $now,
                'refundPayment' => $booking->payment,
                'refundDocument' => $booking->document,
                'tstamp' => $now,
            ], ['id' => $withdrawal['id']]);

            // The subscriptions this withdrawal ended (P78), for the mail (P81).
            $ended = array_map(strval(...), $this->connection->fetchFirstColumn(
                "SELECT s.subscriptionNumber FROM tl_consho_subscription s
                   JOIN tl_consho_withdrawal_item wi ON wi.orderItem = s.orderItem AND wi.pid = ? AND wi.acceptedQuantity > 0
                  WHERE s.firstOrder = ? AND s.state = 'ended' AND s.endsAt = ?
                  ORDER BY s.id",
                [(int) $withdrawal['id'], $orderId, (int) $withdrawal['decidedAt']],
            ));

            $booked = ['order' => $order, 'number' => (string) $withdrawal['number'], 'booking' => $booking, 'kept' => (string) $kept, 'ended' => $ended];

            return ProcessingResult::done('refunded', [(string) $withdrawal['number']]);
        });

        if (null === $booked) {
            return $result;
        }

        $order = $booked['order'];
        $orderId = (int) $order['id'];
        $booking = $booked['booking'];

        // After the commit (R1): paid out, archived — the invoice issued with
        // it (F3a) first, then the correction.
        $documents = $this->refunds->finish($booking, $now);

        $this->statuses->advance($orderId, OrderEvent::WithdrawalRefunded, $now);

        $language = (string) ($order['language'] ?? '');
        $sent = $this->mail->refundBooked(
            $orderId,
            $booked['number'],
            BigDecimal::of($booking->payout)->isPositive() ? $this->money->format($booking->payout, $booking->currency, $language) : '',
            $this->money->format($booking->amount, $booking->currency, $language),
            $documents,
            $this->links->forMail($order) ?? '',
            BigDecimal::of($booking->remaining)->isPositive() ? $this->money->format($booking->remaining, $booking->currency, $language) : '',
            BigDecimal::of($booked['kept'])->isPositive() ? $this->money->format($booked['kept'], $booking->currency, $language) : '',
            $booked['ended'],
            // WG11: what of it went back onto the vouchers.
            BigDecimal::of($booking->vouchers)->isPositive() ? $this->money->format($booking->vouchers, $booking->currency, $language) : '',
        );

        return $sent ? $result : ProcessingResult::done('refundedNoMail', [$booked['number']]);
    }

    /**
     * What the page proposes (F1): the goods lines of the acceptance, and the
     * delivery costs still refundable.
     *
     * @return array{goods: string, shippingLeft: string, vouchers: string, provider: string, providerMethods: list<string>, outside: list<array{id: int, reference: string, amount: string, currency: string}>}
     */
    public function proposal(int $withdrawalId): array
    {
        $withdrawal = $this->withdrawal($withdrawalId);
        $orderId = (int) ($withdrawal['pid'] ?? 0);

        if ($orderId < 1) {
            return ['goods' => '0', 'shippingLeft' => '0', 'vouchers' => '0', 'provider' => '0', 'providerMethods' => [], 'outside' => []];
        }

        $order = $this->orders->order($orderId);
        $goods = $this->total($this->lines($orderId, $withdrawalId, BigDecimal::zero()), (string) ($order['currency'] ?? ''))->negated();

        return [
            'goods' => (string) $goods,
            'shippingLeft' => (string) $this->shippingLeft($orderId),
            // WG11: what the order's vouchers paid and can take back — a
            // single-purpose one only from its pot's lines (EZ-4).
            'vouchers' => null === $this->vouchers ? '0' : (string) $this->vouchers->onVouchers($orderId, (string) $this->vouchers->paidTotal($orderId), $this->lines($orderId, $withdrawalId, BigDecimal::zero())),
            // Block Zahlung Mollie (R2): what the providers could pay back,
            // and through which methods — the option is offered, and
            // preselected, only where this is positive.
            'provider' => (string) array_reduce($this->payouts->refundable($orderId), static fn (BigDecimal $sum, string $left): BigDecimal => $sum->plus($left), BigDecimal::zero()),
            'providerMethods' => $this->payouts->methodTitles($orderId),
            // Spec 9.6 B: refunds made at the provider and still open — the
            // option is then not preselected, and the form names them.
            'outside' => $this->payouts->openForeign($orderId),
        ];
    }

    /**
     * @param \Closure(array<string, mixed>, array<string, mixed>): ProcessingResult $action
     */
    private function locked(int $withdrawalId, \Closure $action): ProcessingResult
    {
        $orderId = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_withdrawal WHERE id = ?', [$withdrawalId]);

        if ($orderId < 1) {
            return ProcessingResult::refused('unknown');
        }

        $this->connection->beginTransaction();

        try {
            $order = $this->orders->lockOrder($orderId);
            $withdrawal = null === $order ? null : $this->withdrawal($withdrawalId);

            if (null === $order || null === $withdrawal) {
                $this->connection->commit();

                return ProcessingResult::refused('unknown');
            }

            $result = $action($withdrawal, $order);

            if ($result->done) {
                $this->connection->commit();
            } else {
                $this->connection->rollBack();
            }

            return $result;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function withdrawal(int $withdrawalId): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal WHERE id = ?', [$withdrawalId]);

        return false === $row ? null : $row;
    }

    /**
     * @return array<int, array<string, string>> the goods lines of the order
     */
    private function goods(int $orderId): array
    {
        return $this->creditLines->goods($orderId);
    }

    /**
     * The quantities this withdrawal accepted, per order item.
     *
     * @return array<int|string, mixed>
     */
    private function accepted(int $withdrawalId): array
    {
        return $this->connection->fetchAllKeyValue('SELECT orderItem, SUM(acceptedQuantity) FROM tl_consho_withdrawal_item WHERE pid = ? GROUP BY orderItem', [$withdrawalId]);
    }

    /**
     * Quantities per order item gone back otherwise: in OTHER accepted
     * withdrawals and in decided rescissions (RK-4, spec 3.4) — a piece is
     * withdrawn or rescinded, never both.
     *
     * @return array<int, string>
     */
    private function acceptedElsewhere(int $orderId, int $withdrawalId): array
    {
        $elsewhere = array_map(strval(...), $this->connection->fetchAllKeyValue(
            "SELECT wi.orderItem, SUM(wi.acceptedQuantity) FROM tl_consho_withdrawal_item wi
               JOIN tl_consho_withdrawal w ON w.id = wi.pid
              WHERE w.pid = ? AND w.id != ? AND w.state = 'accepted'
              GROUP BY wi.orderItem",
            [$orderId, $withdrawalId],
        ));

        $rescinded = $this->connection->fetchAllKeyValue(
            'SELECT ci.orderItem, SUM(ci.rescindedQuantity) FROM tl_consho_complaint_item ci
               JOIN tl_consho_complaint c ON c.id = ci.pid
              WHERE c.pid = ? AND ci.rescindedQuantity > 0
              GROUP BY ci.orderItem',
            [$orderId],
        );

        foreach ($rescinded as $itemId => $quantity) {
            $elsewhere[(int) $itemId] = (string) BigDecimal::of($elsewhere[(int) $itemId] ?? '0')->plus((string) $quantity);
        }

        return $elsewhere;
    }

    /**
     * DL9: the entitlements of one-time service lines withdrawn in full end
     * with the acceptance — the customer left the contract, and the access
     * must not outlive it. A subscription line is SubscriptionPurchaseCancellation's
     * (Abo-8, P78), which ends the contract and revokes there.
     *
     * @param list<int> $itemIds order items whose whole quantity is now withdrawn
     */
    private function revokeServices(int $orderId, array $itemIds, int $now): void
    {
        if ([] === $itemIds) {
            return;
        }

        // Read first, so the delivery modules can be told which (E6).
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_entitlement
              WHERE pid = ? AND revoked = 0 AND orderItem IN (
                    SELECT id FROM tl_consho_order_item WHERE pid = ? AND kind = ? AND subscriptionRule = 0 AND id IN (?)
              )',
            [$orderId, $orderId, ProductKind::Service->value, $itemIds],
            [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ArrayParameterType::INTEGER],
        ));

        if ([] === $ids) {
            return;
        }

        $this->connection->executeStatement(
            'UPDATE tl_consho_entitlement SET revoked = 1, tstamp = ? WHERE id IN (?)',
            [$now, $ids],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
        );

        $this->revocations?->revoked($ids);
    }

    /**
     * The delivery costs of the order not yet refunded by another withdrawal.
     */
    private function shippingLeft(int $orderId): BigDecimal
    {
        return $this->creditLines->shippingLeft($orderId);
    }

    /**
     * Whether this refund completes everything: every goods line accepted in
     * full by this withdrawal, all delivery costs with it, and nothing else
     * refunded or still to be refunded — only then does a cancellation of the
     * invoice say what happened.
     */
    private function everything(int $orderId, int $withdrawalId, BigDecimal $shipping, BigDecimal $shippingLeft): bool
    {
        $earlier = (int) $this->connection->fetchOne(
            // Another accepted withdrawal counts, refunded or not: not yet
            // refunded, it is booked on its own, and this refund would cancel
            // the invoice in its stead.
            "SELECT COUNT(*) FROM tl_consho_withdrawal WHERE pid = ? AND id != ? AND state = 'accepted'",
            [$orderId, $withdrawalId],
        ) + (int) $this->connection->fetchOne(
            // RK-4 (L6): a complaint's refund of any kind counts as earlier,
            // and so does a decided rescission or price reduction not yet
            // refunded — the grand total would give it back a second time with
            // its own refund.
            "SELECT COUNT(*) FROM tl_consho_complaint WHERE pid = ?
                AND (refundedAt IS NOT NULL OR (remedy IN ('rescission', 'reduction') AND decidedAt IS NOT NULL AND state <> 'rejected'))",
            [$orderId],
        );

        if ($earlier > 0 || !$shipping->isEqualTo($shippingLeft)) {
            return false;
        }

        $mine = array_map(strval(...), $this->connection->fetchAllKeyValue('SELECT orderItem, SUM(acceptedQuantity) FROM tl_consho_withdrawal_item WHERE pid = ? GROUP BY orderItem', [$withdrawalId]));
        $elsewhere = $this->acceptedElsewhere($orderId, $withdrawalId);

        foreach ($this->goods($orderId) as $itemId => $good) {
            if (BigDecimal::of($mine[$itemId] ?? '0')->plus($elsewhere[$itemId] ?? '0')->isLessThan($good['quantity'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The goods credit the balance already counts for this withdrawal while it
     * is accepted and not refunded — taken back out before the refund amount
     * goes in, so it is not subtracted twice.
     */
    private function goodsCredit(int $orderId, int $withdrawalId): BigDecimal
    {
        $credit = BigDecimal::zero();

        foreach ($this->credits($orderId, $withdrawalId) as $line) {
            $credit = $credit->plus($line);
        }

        // Less the value compensation the balance keeps owed (P79).
        $kept = (string) ($this->connection->fetchOne('SELECT valueCompensation FROM tl_consho_withdrawal WHERE id = ?', [$withdrawalId]) ?: '0');

        return BigDecimal::max(BigDecimal::zero(), $credit->minus($kept));
    }

    /**
     * What the service lines of this withdrawal credit, gross — the most
     * value compensation that can be kept back (P79).
     */
    private function serviceCredit(int $orderId, int $withdrawalId): BigDecimal
    {
        $credit = BigDecimal::zero();

        foreach ($this->credits($orderId, $withdrawalId, ProductKind::Service) as $line) {
            $credit = $credit->plus($line);
        }

        return $credit;
    }

    /**
     * What each accepted line of this withdrawal credits, gross: its share of
     * what the line cost, its part of the order's reductions taken out
     * (H-5, R1).
     *
     * @return array<int, BigDecimal> order item id => credit
     */
    private function credits(int $orderId, int $withdrawalId, ProductKind|null $kind = null): array
    {
        return $this->creditLines->credits($orderId, $this->accepted($withdrawalId), $kind, $this->decidedAt($withdrawalId));
    }

    /**
     * The lines of an invoice correction for this withdrawal's accepted
     * quantities (CreditLines::lines()).
     *
     * @return list<array<string, string>>
     */
    private function lines(int $orderId, int $withdrawalId, BigDecimal $shipping, BigDecimal|null $kept = null): array
    {
        return $this->creditLines->lines($orderId, $this->accepted($withdrawalId), $shipping, $kept, $this->decidedAt($withdrawalId));
    }

    /**
     * When the withdrawal was accepted: a complaint's price reduction decided
     * before it travels with the goods (RK-4, L3).
     */
    private function decidedAt(int $withdrawalId): int
    {
        return (int) $this->connection->fetchOne('SELECT decidedAt FROM tl_consho_withdrawal WHERE id = ?', [$withdrawalId]);
    }

    /**
     * @param list<array<string, string>> $lines
     */
    private function total(array $lines, string $currency): BigDecimal
    {
        return $this->creditLines->total($lines, $currency);
    }
}
