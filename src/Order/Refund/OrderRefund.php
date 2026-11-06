<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order\Refund;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeNotConfigured;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ProcessingResult;

/**
 * The document and the money of a refund, whatever it is booked for
 * (RefundOccasion): a withdrawal today, a complaint's rescission or price
 * reduction next.
 *
 * book() runs under the caller's order lock, in its transaction, and writes
 * nothing of the occasion's own — the caller records the booking on its row.
 * finish() runs after the commit.
 *
 * The document:
 *
 *  - everything going back in this one step → the invoice is CANCELLED, and
 *    the refund is the grand total — payment fees included, everything the
 *    customer paid;
 *  - otherwise an INVOICE CORRECTION with the occasion's lines;
 *  - no invoice yet → it is issued now, then cancelled or corrected.
 *
 * The payout is what the customer actually paid over what is still owed —
 * never money that never came in. An unpaid order gets its claim lowered,
 * not a refund.
 */
class OrderRefund
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documentRows,
        private readonly DocumentIssuerInterface $documents,
        private readonly DocumentArchiverInterface $archiver,
        private readonly OrderBalanceReaderInterface $balances,
        // The payment method's part, booked against the payments it pays
        // back: through the provider, or by hand.
        private readonly RefundPayout $payouts,
        private readonly CreditLines $creditLines,
        // Block Wertgutscheine (WG-6): refunds onto vouchers first (WG11).
        private readonly VoucherReturn|null $vouchers = null,
    ) {
    }

    /**
     * @param bool        $throughProvider pay the payment method's part back through the provider
     * @param string|null $confirmed       the provider's amount the operator confirmed (R5); without it, an amount
     *                                     through the provider is refused for confirmation and nothing is booked
     * @param list<int>   $foreign         refunds already made at the provider that are part of this one
     *                                     (Geldflüsse T5): booked first, so the provider pays only the rest
     * @param string      $by              the back-end user who booked it
     */
    public function book(RefundOccasion $occasion, int $now, bool $throughProvider = false, string|null $confirmed = null, array $foreign = [], string $by = ''): RefundBooking|ProcessingResult
    {
        $order = $occasion->order;
        $orderId = (int) $order['id'];
        $currency = (string) ($order['currency'] ?? '');
        $digits = '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
        $lines = $occasion->lines;
        $everything = $occasion->everything;
        $invoiceId = $this->documentRows->findIdByOrderAndType($orderId, DocumentType::Invoice);

        $amount = $everything
            ? BigDecimal::of((string) $order['grandTotal'])->toScale($digits, RoundingMode::HalfUp)
            : $this->creditLines->total($lines, $currency)->negated();

        // What the customer paid beyond what is still owed after this —
        // never a refund of money that never came in.
        $balance = $this->balances->of($orderId);
        $paid = BigDecimal::of(null === $balance ? '0' : $balance->paid);
        $claimAfter = BigDecimal::of(null === $balance ? '0' : $balance->claim)->plus($occasion->credited)->minus($amount);
        $payout = BigDecimal::min($amount, BigDecimal::max(BigDecimal::zero(), $paid->minus(BigDecimal::max(BigDecimal::zero(), $claimAfter))))->toScale($digits, RoundingMode::HalfUp);

        // What is still to pay after this (F3b) — the mail says so, since
        // no invoice states that amount.
        $remaining = BigDecimal::max(BigDecimal::zero(), $claimAfter->minus($paid))->toScale($digits, RoundingMode::HalfUp);

        /*
         * WG11: the payout goes back where it came from — onto the
         * order's vouchers first, up to what they paid (the same means of
         * payment, § 14 Abs. 1 FAGG, § 357 Abs. 3 BGB), the rest to the
         * payment method. The split is fixed: money moved off a voucher
         * would be paid back by hand against no payment of its own, and
         * nothing could ever tie it to one.
         *
         * EZ-4: a single-purpose voucher takes back only what the credited
         * lines of its pot give back. A cancellation (everything) gives
         * back all.
         */
        $creditLines = $everything ? null : $lines;
        $voucherPaid = null === $this->vouchers
            ? BigDecimal::zero()->toScale($digits)
            : $this->vouchers->onVouchers($orderId, (string) $this->vouchers->paidTotal($orderId), $creditLines)->toScale($digits, RoundingMode::Down);

        // Never more than this refund pays out.
        $toVouchers = BigDecimal::min($voucherPaid, $payout);

        // Within the most above the plan books it all; read back so the
        // documents, the payments and the mail state what is booked.
        if ($toVouchers->isPositive() && null !== $this->vouchers) {
            $toVouchers = $this->vouchers->onVouchers($orderId, (string) $toVouchers, $creditLines)->toScale($digits, RoundingMode::Down);
        }

        $toMethod = $payout->minus($toVouchers);

        /*
         * R5: money that leaves through a provider cannot be taken back, so
         * the operator confirms the amount first — computed here, before
         * anything is written, and nothing booked (the lock rolls back on a
         * refusal). The confirmed amount must still hold under the lock: a
         * payment refunded in between changes it.
         */
        // Geldflüsse T5: the split over the ways, as book() takes it —
        // the chosen refunds made at the provider first, then the
        // provider, then by hand. A chosen one that no longer fits (more
        // than goes to the payment method, resolved meanwhile) books
        // nothing: its money must not go another way.
        $ways = null;

        if ($toMethod->isPositive()) {
            $ways = $this->payouts->ways($orderId, (string) $toMethod, null, $foreign, $throughProvider);
        }

        if ([] !== $foreign && null === $ways) {
            return ProcessingResult::refused('foreignInvalid');
        }

        if ($throughProvider) {
            $viaProvider = BigDecimal::of(null === $ways ? '0' : $ways['provider'])->toScale($digits, RoundingMode::Down);
            $byHand = BigDecimal::of(null === $ways ? '0' : $ways['hand'])->toScale($digits, RoundingMode::HalfUp);
            $recorded = BigDecimal::of(null === $ways ? '0' : $ways['foreign'])->toScale($digits, RoundingMode::HalfUp);

            if (null === $confirmed && $viaProvider->isPositive()) {
                return ProcessingResult::refused('confirmPayout', [(string) $viaProvider, (string) $byHand, (string) $recorded], [ProcessingResult::MONEY, ProcessingResult::MONEY, ProcessingResult::MONEY]);
            }

            if (null !== $confirmed && !self::sameAmount($confirmed, $viaProvider)) {
                return ProcessingResult::refused('payoutChanged');
            }
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
             * With everything going back too, and cancelled at once: the
             * sale was made and its tax counted from the payment on
             * (OssLedger), and only a document takes it back — a refund
             * without one left the sale standing in the OSS report.
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
                    : $this->documents->correct($invoiceId, $lines, $occasion->reference, $toVouchers->isPositive() ? ($this->vouchers?->prepaidFor($orderId, (string) $toVouchers, (string) ($order['language'] ?? ''), $creditLines) ?? []) : [], $occasion->about);
            }
        } catch (NumberRangeNotConfigured $e) {
            return ProcessingResult::refused('numberRangeMissing', [$e->type]);
        }

        $paymentId = 0;
        $voucherPayments = $toVouchers->isPositive() && null !== $this->vouchers
            ? $this->vouchers->refund($orderId, (string) $toVouchers, $now, $occasion->reference, (string) ($order['language'] ?? ''), $creditLines)
            : [];
        $payoutRows = [];

        if ($toMethod->isPositive()) {
            // One refund over its ways (Geldflüsse T3/T5). By hand is
            // booked `manual` and tied to the payments it pays back, so
            // nothing offers this money again and a refund made at the
            // provider can be assigned to it. finish() after the commit
            // sends only the provider's rows.
            $payoutRows = $this->payouts->book($orderId, (string) $toMethod, $occasion->reference, $now, $throughProvider, null, $foreign, $by);
            $paymentId = $payoutRows[0];
        }

        // Paid back onto vouchers alone: the first of their payments.
        $paymentId = 0 !== $paymentId ? $paymentId : ($voucherPayments[0] ?? 0);

        return new RefundBooking(
            (string) $amount,
            (string) $payout,
            (string) $remaining,
            (string) $toVouchers,
            $currency,
            $documentId,
            $issuedInvoice,
            $paymentId,
            $payoutRows,
        );
    }

    /**
     * After the commit (R1): the provider's rows paid out, the documents
     * archived. Never throws for a payout — a refusal stays on the row and
     * becomes a finding.
     *
     * @return list<int> the documents, in the order a reader of the mail needs them: the invoice issued with
     *                   the refund (F3a) first, then the cancellation or correction
     */
    public function finish(RefundBooking $booking, int $now): array
    {
        $this->payouts->payOut($booking->payoutRows, $now);

        $documents = array_values(array_filter([$booking->invoice, $booking->document], static fn (int|null $id): bool => null !== $id && $id > 0));

        foreach ($documents as $documentId) {
            $this->archiver->archive($documentId);
        }

        return $documents;
    }

    /**
     * The confirmed amount comes back from a form field; anything that is not
     * a number confirms nothing.
     */
    private static function sameAmount(string $confirmed, BigDecimal $amount): bool
    {
        try {
            return BigDecimal::of($confirmed)->isEqualTo($amount);
        } catch (MathException) {
            return false;
        }
    }
}
