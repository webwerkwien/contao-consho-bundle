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
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Order\DiscountShares;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeNotConfigured;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
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
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly DocumentRepositoryInterface $documentRows,
        private readonly DocumentIssuerInterface $documents,
        private readonly DocumentArchiverInterface $archiver,
        private readonly DocumentPayloadBuilder $payloads,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly OrderStatusAdvancer $statuses,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        private readonly MoneyFormatter $money,
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
     * @param string|null $toVouchers   the part of the payout booked back onto the order's
     *                                  vouchers (WG11); null: all they paid, up to the payout
     */
    public function refund(int $withdrawalId, string $shipping, int $now, string|null $compensation = null, string|null $toVouchers = null): ProcessingResult
    {
        $booked = null;

        $result = $this->locked($withdrawalId, function (array $withdrawal, array $order) use ($shipping, $now, $compensation, $toVouchers, &$booked): ProcessingResult {
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
            $invoiceId = $this->documentRows->findIdByOrderAndType($orderId, DocumentType::Invoice);
            $everything = $kept->isZero() && $this->everything($orderId, (int) $withdrawal['id'], $shippingAmount, $shippingLeft);

            $amount = $everything
                ? BigDecimal::of((string) $order['grandTotal'])->toScale($digits, RoundingMode::HalfUp)
                : $this->total($lines, $currency)->negated();

            // What the customer paid beyond what is still owed after this —
            // never a refund of money that never came in.
            $balance = $this->balances->of($orderId);
            $paid = BigDecimal::of(null === $balance ? '0' : $balance->paid);
            $claimAfter = BigDecimal::of(null === $balance ? '0' : $balance->claim)->plus($this->goodsCredit($orderId, (int) $withdrawal['id']))->minus($amount);
            $payout = BigDecimal::min($amount, BigDecimal::max(BigDecimal::zero(), $paid->minus(BigDecimal::max(BigDecimal::zero(), $claimAfter))))->toScale($digits, RoundingMode::HalfUp);

            // What is still to pay after this (F3b) — the mail says so, since
            // no invoice states that amount.
            $remaining = BigDecimal::max(BigDecimal::zero(), $claimAfter->minus($paid))->toScale($digits, RoundingMode::HalfUp);

            /*
             * WG11: the payout goes onto the order's vouchers first, up to
             * what they paid — the same means of payment (§ 14 Abs. 1 FAGG,
             * § 357 Abs. 3 BGB) —, the rest to the payment method. The
             * operator may change the split (null: the proposal).
             *
             * EZ-4: a single-purpose voucher takes back only what the credited
             * lines of its pot give back, so that is the most the field
             * takes. A cancellation (everything) gives back all.
             */
            $creditLines = $everything ? null : $lines;
            $voucherPaid = null === $this->vouchers
                ? BigDecimal::zero()->toScale($digits)
                : $this->vouchers->onVouchers($orderId, (string) $this->vouchers->paidTotal($orderId), $creditLines)->toScale($digits, RoundingMode::Down);

            try {
                $toVouchers = null === $toVouchers
                    ? $voucherPaid
                    : BigDecimal::of('' !== trim($toVouchers) ? str_replace(',', '.', trim($toVouchers)) : '0')->toScale($digits, RoundingMode::HalfUp);
            } catch (MathException) {
                return ProcessingResult::refused('invalidVoucherShare', [(string) $voucherPaid], [ProcessingResult::MONEY]);
            }

            if ($toVouchers->isNegative() || $toVouchers->isGreaterThan($voucherPaid)) {
                return ProcessingResult::refused('invalidVoucherShare', [(string) $voucherPaid], [ProcessingResult::MONEY]);
            }

            // "At most": the field is filled before the delivery share is
            // decided, so it never asks for more than this refund pays out.
            $toVouchers = BigDecimal::min($toVouchers, $payout);

            // Within the most above the plan books it all; read back so the
            // documents, the payments and the mail state what is booked.
            if ($toVouchers->isPositive() && null !== $this->vouchers) {
                $toVouchers = $this->vouchers->onVouchers($orderId, (string) $toVouchers, $creditLines)->toScale($digits, RoundingMode::Down);
            }

            $documentId = 0;
            $issuedInvoice = null;

            try {
                /*
                 * No invoice yet (F3a): the invoice is issued NOW, then corrected. Left
                 * to the shipment ("Rechnung bei Versand"), it would state the
                 * whole order and never be corrected — this refund is booked
                 * already. The shipment finds it and issues no second one
                 * (issueOnce).
                 *
                 * With everything withdrawn too, and cancelled at once: the
                 * sale was made and its tax counted from the payment on
                 * (OssLedger), and only a document takes it back — a
                 * withdrawal without one left the sale standing in the OSS
                 * report.
                 *
                 * Without the Giro-Code (F3b): its amount would be the whole
                 * order, not what is left to pay. The mail names that.
                 */
                if (null === $invoiceId) {
                    $invoiceId = $issuedInvoice = $this->documents->issueOnce($orderId, (int) $order['shop'], DocumentType::Invoice, null, false);
                }

                // Nothing left to credit (all of it kept back): no correction.
                if ($everything || [] !== $lines) {
                    $documentId = $everything
                        ? $this->documents->cancel($invoiceId)
                        : $this->documents->correct($invoiceId, $lines, (string) $withdrawal['number'], $toVouchers->isPositive() ? ($this->vouchers?->prepaidFor($orderId, (string) $toVouchers, (string) ($order['language'] ?? ''), $creditLines) ?? []) : []);
                }
            } catch (NumberRangeNotConfigured $e) {
                return ProcessingResult::refused('numberRangeMissing', [$e->type]);
            }

            $paymentId = 0;
            $voucherPayments = $toVouchers->isPositive() && null !== $this->vouchers
                ? $this->vouchers->refund($orderId, (string) $toVouchers, $now, (string) $withdrawal['number'], (string) ($order['language'] ?? ''), $creditLines)
                : [];
            $toMethod = $payout->minus($toVouchers);

            if ($toMethod->isPositive()) {
                $this->connection->insert('tl_consho_payment', [
                    'pid' => $orderId,
                    'tstamp' => $now,
                    'receivedAt' => $now,
                    'amount' => (string) $toMethod->negated(),
                    'method' => 'refund',
                    'reference' => (string) $withdrawal['number'],
                    'lockedAt' => $now,
                ]);
                $paymentId = (int) $this->connection->lastInsertId();
            }

            // Paid back onto vouchers alone: the first of their payments.
            $paymentId = 0 !== $paymentId ? $paymentId : ($voucherPayments[0] ?? 0);

            $this->connection->update('tl_consho_withdrawal', [
                'refundShipping' => (string) $shippingAmount,
                'valueCompensation' => (string) $kept,
                'refundAmount' => (string) $amount,
                'refundedAt' => $now,
                'refundPayment' => $paymentId,
                'refundDocument' => $documentId,
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

            $booked = ['order' => $order, 'number' => (string) $withdrawal['number'], 'invoice' => $issuedInvoice, 'document' => $documentId, 'amount' => (string) $amount, 'payout' => (string) $payout, 'remaining' => (string) $remaining, 'currency' => $currency, 'kept' => (string) $kept, 'ended' => $ended, 'vouchers' => (string) $toVouchers];

            return ProcessingResult::done('refunded', [(string) $withdrawal['number']]);
        });

        if (null === $booked) {
            return $result;
        }

        $order = $booked['order'];
        $orderId = (int) $order['id'];

        // The invoice issued with it (F3a) first, then the correction — the
        // order a reader of the mail needs them in.
        $documents = array_values(array_filter([$booked['invoice'], $booked['document']], static fn (int|null $id): bool => null !== $id && $id > 0));

        foreach ($documents as $documentId) {
            $this->archiver->archive($documentId);
        }

        $this->statuses->advance($orderId, OrderEvent::WithdrawalRefunded, $now);

        $language = (string) ($order['language'] ?? '');
        $sent = $this->mail->refundBooked(
            $orderId,
            $booked['number'],
            BigDecimal::of($booked['payout'])->isPositive() ? $this->money->format($booked['payout'], $booked['currency'], $language) : '',
            $this->money->format($booked['amount'], $booked['currency'], $language),
            $documents,
            $this->links->forMail($order) ?? '',
            BigDecimal::of($booked['remaining'])->isPositive() ? $this->money->format($booked['remaining'], $booked['currency'], $language) : '',
            BigDecimal::of($booked['kept'])->isPositive() ? $this->money->format($booked['kept'], $booked['currency'], $language) : '',
            $booked['ended'],
            // WG11: what of it went back onto the vouchers.
            BigDecimal::of($booked['vouchers'])->isPositive() ? $this->money->format($booked['vouchers'], $booked['currency'], $language) : '',
        );

        return $sent ? $result : ProcessingResult::done('refundedNoMail', [$booked['number']]);
    }

    /**
     * What the page proposes (F1): the goods lines of the acceptance, and the
     * delivery costs still refundable.
     *
     * @return array{goods: string, shippingLeft: string, vouchers: string}
     */
    public function proposal(int $withdrawalId): array
    {
        $withdrawal = $this->withdrawal($withdrawalId);
        $orderId = (int) ($withdrawal['pid'] ?? 0);

        if ($orderId < 1) {
            return ['goods' => '0', 'shippingLeft' => '0', 'vouchers' => '0'];
        }

        $order = $this->orders->order($orderId);
        $goods = $this->total($this->lines($orderId, $withdrawalId, BigDecimal::zero()), (string) ($order['currency'] ?? ''))->negated();

        return [
            'goods' => (string) $goods,
            'shippingLeft' => (string) $this->shippingLeft($orderId),
            // WG11: what the order's vouchers paid and can take back — a
            // single-purpose one only from its pot's lines (EZ-4).
            'vouchers' => null === $this->vouchers ? '0' : (string) $this->vouchers->onVouchers($orderId, (string) $this->vouchers->paidTotal($orderId), $this->lines($orderId, $withdrawalId, BigDecimal::zero())),
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
        return array_filter(
            $this->items($orderId),
            static fn (array $row): bool => OrderLineType::stored($row['lineType'])->isGoods(),
        );
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function items(int $orderId): array
    {
        $items = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_order_item WHERE pid = ? ORDER BY id', [$orderId]) as $row) {
            $items[(int) $row['id']] = array_map(static fn ($v): string => (string) $v, $row);
        }

        return $items;
    }

    /**
     * Accepted quantities per order item in OTHER accepted withdrawals.
     *
     * @return array<int, string>
     */
    private function acceptedElsewhere(int $orderId, int $withdrawalId): array
    {
        return array_map(strval(...), $this->connection->fetchAllKeyValue(
            "SELECT wi.orderItem, SUM(wi.acceptedQuantity) FROM tl_consho_withdrawal_item wi
               JOIN tl_consho_withdrawal w ON w.id = wi.pid
              WHERE w.pid = ? AND w.id != ? AND w.state = 'accepted'
              GROUP BY wi.orderItem",
            [$orderId, $withdrawalId],
        ));
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
        $total = BigDecimal::zero();

        foreach ($this->items($orderId) as $row) {
            if (OrderLineType::Shipping === OrderLineType::stored($row['lineType'])) {
                $total = $total->plus($row['lineTotal']);
            }
        }

        $refunded = (string) ($this->connection->fetchOne(
            "SELECT SUM(refundShipping) FROM tl_consho_withdrawal WHERE pid = ? AND refundedAt IS NOT NULL AND state = 'accepted'",
            [$orderId],
        ) ?: '0');

        return BigDecimal::max(BigDecimal::zero(), $total->minus($refunded));
    }

    /**
     * Whether this refund completes everything: every goods line accepted in
     * full over all withdrawals, all delivery costs with it, and no earlier
     * refund — only then does a cancellation of the invoice say what happened.
     */
    private function everything(int $orderId, int $withdrawalId, BigDecimal $shipping, BigDecimal $shippingLeft): bool
    {
        $earlier = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM tl_consho_withdrawal WHERE pid = ? AND id != ? AND refundedAt IS NOT NULL AND state = 'accepted'",
            [$orderId, $withdrawalId],
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
        $items = $this->items($orderId);
        $parts = DiscountShares::of($items);
        $credits = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT orderItem, SUM(acceptedQuantity) FROM tl_consho_withdrawal_item WHERE pid = ? GROUP BY orderItem', [$withdrawalId]) as $itemId => $accepted) {
            $item = $items[(int) $itemId] ?? null;

            if (null === $item || !BigDecimal::of((string) $accepted)->isPositive() || (null !== $kind && $kind->value !== ($item['kind'] ?? ''))) {
                continue;
            }

            $credits[(int) $itemId] = DiscountShares::credit($item, $parts[(int) $itemId] ?? [], (string) $accepted);
        }

        return $credits;
    }

    /**
     * The lines of an invoice correction: the accepted goods, and the delivery
     * costs spread over the shipping lines by their share — every amount
     * negative, every quantity positive (the cancellation's rule). The value
     * compensation (P79) lowers the credit of the service lines, in their
     * order; a service line kept back whole is left out.
     *
     * The reductions of the order go back with the goods (H-5, R1): each
     * reduction line gives back the parts of the withdrawn goods, as one line
     * of its own after the goods — positive, under its own title, rate and
     * country, the cancellation's rule turned round. What a service line keeps
     * back is taken from what it cost after its reductions.
     *
     * @return list<array<string, string>>
     */
    private function lines(int $orderId, int $withdrawalId, BigDecimal $shipping, BigDecimal|null $kept = null): array
    {
        $items = $this->items($orderId);
        $parts = DiscountShares::of($items);
        $lines = [];
        $kept ??= BigDecimal::zero();
        /** @var array<int, array{net: BigDecimal, gross: BigDecimal}> $reductions */
        $reductions = [];

        foreach ($this->connection->fetchAllKeyValue('SELECT orderItem, SUM(acceptedQuantity) FROM tl_consho_withdrawal_item WHERE pid = ? GROUP BY orderItem', [$withdrawalId]) as $itemId => $accepted) {
            $item = $items[(int) $itemId] ?? null;
            $share = BigDecimal::of((string) $accepted);

            if (null === $item || !$share->isPositive() || !BigDecimal::of($item['quantity'])->isPositive()) {
                continue;
            }

            $quantity = BigDecimal::of($item['quantity']);
            $gross = BigDecimal::of($item['lineTotal'])->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp);
            $tax = BigDecimal::of($item['taxAmount'])->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp);
            $unit = BigDecimal::of($item['unitPrice']);

            $credit = DiscountShares::credit($item, $parts[(int) $itemId] ?? [], $share);

            if ($kept->isPositive() && ProductKind::Service->value === ($item['kind'] ?? '') && $credit->isPositive()) {
                $off = BigDecimal::min($kept, $credit);
                $kept = $kept->minus($off);

                if ($off->isEqualTo($credit)) {
                    continue;
                }

                $left = $gross->minus($off);

                // Net and tax from what is left, like the delivery share below.
                $rate = BigDecimal::of('' !== ($item['taxRate'] ?? '') ? $item['taxRate'] : '0');
                $net = $left->dividedBy(BigDecimal::one()->plus($rate->dividedBy(100, 6, RoundingMode::HalfUp)), 4, RoundingMode::HalfUp);
                $tax = $left->minus($net);
                $unit = $net->dividedBy($share, 4, RoundingMode::HalfUp);
                $gross = $left;
            }

            $lines[] = $this->line($item, (string) $share, [
                'unitPrice' => (string) $unit,
                'taxAmount' => (string) $tax,
                'lineTotal' => (string) $gross,
            ]);

            foreach ($parts[(int) $itemId] ?? [] as $part) {
                $reduction = $reductions[$part['line']] ?? ['net' => BigDecimal::zero(), 'gross' => BigDecimal::zero()];
                $reductions[$part['line']] = [
                    'net' => $reduction['net']->plus($part['net']->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp)),
                    'gross' => $reduction['gross']->plus($part['gross']->multipliedBy($share)->dividedBy($quantity, 4, RoundingMode::HalfUp)),
                ];
            }
        }

        foreach ($reductions as $lineId => $reduction) {
            if ($reduction['gross']->isZero() && $reduction['net']->isZero()) {
                continue;
            }

            // Negative here, so line() turns them positive.
            $lines[] = $this->line($items[$lineId], '1', [
                'unitPrice' => (string) $reduction['net'],
                'taxAmount' => (string) $reduction['gross']->minus($reduction['net']),
                'lineTotal' => (string) $reduction['gross'],
            ]);
        }

        if ($shipping->isPositive()) {
            $shippingLines = array_values(array_filter($items, static fn (array $row): bool => OrderLineType::Shipping === OrderLineType::stored($row['lineType'])));
            $total = BigDecimal::zero();

            foreach ($shippingLines as $row) {
                $total = $total->plus($row['lineTotal']);
            }

            $left = $shipping;

            foreach ($shippingLines as $index => $row) {
                $gross = $index === \count($shippingLines) - 1 || !$total->isPositive()
                    ? $left
                    : $shipping->multipliedBy($row['lineTotal'])->dividedBy($total, 4, RoundingMode::HalfUp);
                $left = $left->minus($gross);

                $rate = BigDecimal::of('' !== $row['taxRate'] ? $row['taxRate'] : '0');
                $net = $gross->dividedBy(BigDecimal::one()->plus($rate->dividedBy(100, 6, RoundingMode::HalfUp)), 4, RoundingMode::HalfUp);

                $lines[] = $this->line($row, '1', [
                    'unitPrice' => (string) $net,
                    'taxAmount' => (string) $gross->minus($net),
                    'lineTotal' => (string) $gross,
                ]);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string> $item
     * @param array{unitPrice: string, taxAmount: string, lineTotal: string} $amounts positive for a credit, negative
     *                                                                       for a reduction given back
     *
     * @return array<string, string>
     */
    private function line(array $item, string $quantity, array $amounts): array
    {
        return [
            'productName' => $item['productName'] ?? '',
            'sku' => $item['sku'] ?? '',
            'quantity' => $quantity,
            // Four places like every amount on an invoice (decimal(12,4)),
            // whatever scale the driver or the operator's input brought.
            'unitPrice' => (string) BigDecimal::of($amounts['unitPrice'])->negated()->toScale(4, RoundingMode::HalfUp),
            'taxRate' => $item['taxRate'] ?? '',
            'taxAmount' => (string) BigDecimal::of($amounts['taxAmount'])->negated()->toScale(4, RoundingMode::HalfUp),
            'lineTotal' => (string) BigDecimal::of($amounts['lineTotal'])->negated()->toScale(4, RoundingMode::HalfUp),
            'priceSource' => $item['priceSource'] ?? '',
            'lineType' => $item['lineType'] ?? '',
            // DL4: the correction sums per country and rate like the invoice
            // it corrects (DocumentPayloadBuilder::summariseTax()).
            'taxCountry' => $item['taxCountry'] ?? '',
            // Block R (R-2): a service stays out of the OSS threshold, on a
            // correction too.
            'kind' => $item['kind'] ?? '',
            // EZ-4: which pot a single-purpose voucher may take back from
            // (VoucherReturn); not part of the document.
            'taxClass' => (string) ($item['taxClass'] ?? ''),
        ];
    }

    /**
     * The grand total of these lines exactly as the document will state it:
     * the tax summary's gross, rounded to the currency per rate.
     *
     * @param list<array<string, string>> $lines
     */
    private function total(array $lines, string $currency): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->payloads->summariseTax($lines, $currency) as $entry) {
            $total = $total->plus($entry['gross']);
        }

        return $total;
    }
}
