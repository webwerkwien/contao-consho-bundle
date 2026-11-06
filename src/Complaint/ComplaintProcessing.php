<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Complaint;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Intl\Currencies;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementRevocations;
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
use Webwerkwien\ContaoConshoBundle\Order\Refund\RefundBooking;
use Webwerkwien\ContaoConshoBundle\Order\Refund\RefundOccasion;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Stock\StockReturn;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherReturn;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ProcessingResult;

/**
 * The operator's side of a complaint's rescission and price reduction
 * (block Reklamation, RK-4, decisions L1–L9): decide the remedy with its
 * quantities or amounts, record the return, book the refund.
 *
 * A complaint is one more occasion for the ways that exist (spec 2): the
 * credit lines and the refund are the withdrawal's (CreditLines,
 * OrderRefund), the return into stock is StockReturn's.
 *
 * ── The decision binds (L1) ───────────────────────────────────────────────
 *
 * From the decision on the complaint counts wherever a withdrawal does
 * (OrderCredit and the places that read rescinded quantities): a rescission
 * with its quantities, a price reduction with its amounts. It can be decided
 * anew until the refund is booked; the refund resolves it.
 *
 * ── Every action under the order lock ─────────────────────────────────────
 *
 * Like the withdrawal: the order row is locked first, then the complaint read
 * under it — two operators wait for each other, and the second finds the
 * refund booked.
 */
class ComplaintProcessing
{
    private const REMEDIES = ['rescission', 'reduction'];

    private readonly CreditLines $creditLines;

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
        private readonly RefundPayout $payouts,
        // Optional for the tests built by hand; the container wires them.
        private readonly StockReturn|null $stock = null,
        private readonly VoucherReturn|null $vouchers = null,
        // RK-E1: delivery modules told of revoked entitlements.
        private readonly EntitlementRevocations|null $revocations = null,
        // The customer's mail after the refund.
        private readonly OrderEventMail|null $mail = null,
        private readonly MoneyFormatter|null $money = null,
        private readonly OrderPageLink|null $links = null,
    ) {
        $this->creditLines = new CreditLines($connection, $payloads);
        $this->refunds = new OrderRefund($documentRows, $documents, $archiver, $balances, $payouts, $this->creditLines, $vouchers);
    }

    /**
     * Decide the remedy (L1): a rescission with a quantity per complaint line,
     * a price reduction with a gross amount per complaint line. A line left
     * out or at 0 takes no part.
     *
     * @param array<int, string> $values complaint item id => quantity (rescission, replacement) or gross amount (reduction)
     */
    public function decide(int $complaintId, string $remedy, array $values, int $now): ProcessingResult
    {
        if ('replacement' === $remedy || 'repair' === $remedy) {
            return $this->decideWithoutValues($complaintId, $remedy, $values, $now);
        }

        if (!\in_array($remedy, self::REMEDIES, true)) {
            return ProcessingResult::refused('unknownRemedy');
        }

        return $this->locked($complaintId, function (array $complaint, array $order) use ($remedy, $values, $now): ProcessingResult {
            if (null !== ($refused = $this->undecidable($complaint))) {
                return $refused;
            }

            $orderId = (int) $order['id'];
            $complaintId = (int) $complaint['id'];
            $goods = $this->creditLines->goods($orderId);
            $rows = $this->rows($complaintId);
            $digits = self::digits($order);
            $decided = [];

            foreach ($values as $rowId => $value) {
                try {
                    $amount = BigDecimal::of('' !== trim($value) ? str_replace(',', '.', trim($value)) : '0');
                } catch (MathException) {
                    return ProcessingResult::refused('rescission' === $remedy ? 'invalidQuantity' : 'invalidReduction');
                }

                if ($amount->isZero()) {
                    continue;
                }

                $row = $rows[$rowId] ?? null;
                $itemId = null === $row ? 0 : (int) $row['orderItem'];

                if (null === $row || !isset($goods[$itemId]) || $amount->isNegative()) {
                    return ProcessingResult::refused('rescission' === $remedy ? 'invalidQuantity' : 'invalidReduction');
                }

                $good = $goods[$itemId];

                // A voucher bought is taken back by a withdrawal, which knows
                // whether it was redeemed (WG7); a complaint does not.
                if (ProductKind::Voucher->value === ($good['kind'] ?? '')) {
                    return ProcessingResult::refused('voucherLine', [$good['productName']]);
                }

                if ('rescission' === $remedy) {
                    // L9: a subscription ends by withdrawal or cancellation,
                    // not by a complaint's rescission.
                    if ((int) ($good['subscriptionRule'] ?? 0) > 0) {
                        return ProcessingResult::refused('subscriptionLine', [$good['productName']]);
                    }

                    $left = BigDecimal::of($good['quantity'])->minus($this->returnedElsewhere($orderId, $complaintId, $itemId));
                    $most = BigDecimal::min($left, BigDecimal::of((string) $row['quantity']));

                    if ($amount->isGreaterThan($most)) {
                        return ProcessingResult::refused('tooMuch', [$good['productName'], (string) BigDecimal::max(BigDecimal::zero(), $most)->strippedOfTrailingZeros()], [1 => ProcessingResult::QUANTITY]);
                    }
                } else {
                    $amount = $amount->toScale($digits, RoundingMode::HalfUp);
                    $worth = $this->creditLines->worth($orderId, $itemId, $complaintId)->toScale($digits, RoundingMode::Down);

                    if ($amount->isGreaterThan($worth)) {
                        return ProcessingResult::refused('tooMuchReduction', [$good['productName'], (string) $worth], [1 => ProcessingResult::MONEY]);
                    }
                }

                $decided[$rowId] = (string) $amount;
            }

            if ([] === $decided) {
                return ProcessingResult::refused('nothingDecided');
            }

            /*
             * RK-E1: what an earlier decision locked stays locked. A line
             * rescinded in full had its entitlements revoked, and a new
             * decision that keeps less of it back cannot give them back —
             * a revocation may as well stem from a withdrawal.
             */
            $before = $this->revocable($orderId, $complaintId, $this->rescinded($complaintId));
            $after = 'rescission' === $remedy ? $this->revocable($orderId, $complaintId, $this->byOrderItem($rows, $decided)) : [];

            foreach (array_diff($before, $after) as $itemId) {
                if ($this->hasRevoked($orderId, $itemId)) {
                    return ProcessingResult::refused('revokedLine', [$goods[$itemId]['productName'] ?? '']);
                }
            }

            // Every line is written, so a line left out of a new decision
            // no longer counts with the old one.
            foreach ($rows as $rowId => $row) {
                $this->connection->update('tl_consho_complaint_item', [
                    'rescindedQuantity' => 'rescission' === $remedy ? ($decided[$rowId] ?? '0') : '0',
                    'reduction' => 'reduction' === $remedy && isset($decided[$rowId]) ? $decided[$rowId] : null,
                    'replacementQuantity' => '0',
                    'tstamp' => $now,
                ], ['id' => $rowId]);
            }

            $this->connection->update('tl_consho_complaint', [
                'remedy' => $remedy,
                'state' => 'inProgress',
                'decidedAt' => $now,
                // L5: the delivery costs proposed where the rescission
                // completes the return of every goods line, else nothing.
                'refundShipping' => 'rescission' === $remedy && $this->complete($orderId, $complaintId) ? (string) $this->creditLines->shippingLeft($orderId) : '0',
                'tstamp' => $now,
            ], ['id' => $complaintId]);

            if ('rescission' === $remedy) {
                // Block G: a key line rescinded before its delivery no longer
                // waits for a code.
                $this->stock?->keysFreed($orderId);

                // RK-E1, L9: a service or digital line rescinded in full is
                // no longer usable.
                $this->revoke($orderId, $after, $now);
            }

            return ProcessingResult::done('decided', [(string) $complaint['number']]);
        });
    }

    /**
     * Decide a replacement (RK-5, S1) or a repair (RK-6, A1). Nothing is
     * refunded and the claim stays. A repair has no quantities; a replacement
     * names per line what is sent again, at most the complained quantity (U8)
     * — what is decided is what has to be sent before the complaint is
     * resolved. Without values (an older caller) the complained quantities
     * count.
     *
     * A replacement takes goods only: a service, digital content, a voucher
     * or a subscription line cannot be sent again. A repair fixes what was
     * delivered and may concern any line but a voucher, which a withdrawal
     * takes back (WG7).
     *
     * @param array<int, string> $values complaint item id => quantity to replace
     */
    private function decideWithoutValues(int $complaintId, string $remedy, array $values, int $now): ProcessingResult
    {
        return $this->locked($complaintId, function (array $complaint, array $order) use ($remedy, $values, $now): ProcessingResult {
            if (null !== ($refused = $this->undecidable($complaint))) {
                return $refused;
            }

            $orderId = (int) $order['id'];
            $complaintId = (int) $complaint['id'];
            $goods = $this->creditLines->goods($orderId);
            $rows = $this->rows($complaintId);

            if ([] === $rows) {
                return ProcessingResult::refused('nothingDecided');
            }

            $replace = [];

            if ('replacement' === $remedy) {
                foreach ($rows as $rowId => $row) {
                    $value = $values[$rowId] ?? ([] === $values ? (string) $row['quantity'] : '0');

                    try {
                        $amount = BigDecimal::of('' !== trim($value) ? str_replace(',', '.', trim($value)) : '0');
                    } catch (MathException) {
                        return ProcessingResult::refused('invalidQuantity');
                    }

                    if ($amount->isNegative()) {
                        return ProcessingResult::refused('invalidQuantity');
                    }

                    if ($amount->isGreaterThan(BigDecimal::of((string) $row['quantity']))) {
                        $good = $goods[(int) $row['orderItem']] ?? null;

                        return ProcessingResult::refused('tooMuchReplacement', [(string) ($good['productName'] ?? ''), (string) BigDecimal::of((string) $row['quantity'])->strippedOfTrailingZeros()], [1 => ProcessingResult::QUANTITY]);
                    }

                    if ($amount->isPositive()) {
                        $replace[$rowId] = (string) $amount;
                    }
                }

                if ([] === $replace) {
                    return ProcessingResult::refused('nothingDecided');
                }
            }

            foreach ($rows as $rowId => $row) {
                // Only what is sent again has to be goods.
                if ('replacement' === $remedy && !isset($replace[$rowId])) {
                    continue;
                }

                $good = $goods[(int) $row['orderItem']] ?? null;

                if ('repair' === $remedy) {
                    if (null !== $good && ProductKind::Voucher->value === ($good['kind'] ?? '')) {
                        return ProcessingResult::refused('voucherLine', [(string) $good['productName']]);
                    }

                    continue;
                }

                if (null === $good || ProductKind::Physical->value !== ($good['kind'] ?? '') || (int) ($good['subscriptionRule'] ?? 0) > 0) {
                    return ProcessingResult::refused('notPhysical', [(string) ($good['productName'] ?? '')]);
                }
            }

            // RK-E1: a line an earlier rescission made unusable stays so.
            foreach ($this->revocable($orderId, $complaintId, $this->rescinded($complaintId)) as $itemId) {
                if ($this->hasRevoked($orderId, $itemId)) {
                    return ProcessingResult::refused('revokedLine', [$goods[$itemId]['productName'] ?? '']);
                }
            }

            foreach (array_keys($rows) as $rowId) {
                $this->connection->update('tl_consho_complaint_item', ['rescindedQuantity' => '0', 'reduction' => null, 'replacementQuantity' => $replace[$rowId] ?? '0', 'tstamp' => $now], ['id' => $rowId]);
            }

            $this->connection->update('tl_consho_complaint', [
                'remedy' => $remedy,
                'state' => 'inProgress',
                'decidedAt' => $now,
                'refundShipping' => '0',
                'tstamp' => $now,
            ], ['id' => $complaintId]);

            return ProcessingResult::done('decided', [(string) $complaint['number']]);
        });
    }

    /**
     * Reject (RK-6, A2), with the reason that goes to the customer verbatim
     * (A3). Final: decided and resolved at once. Possible as long as a new
     * decision is — before a refund or a replacement delivery — and it clears
     * what an earlier decision set. A line an earlier rescission made
     * unusable cannot be handed back, so that refuses the rejection.
     *
     * Goods taken into stock from a return go out again: rejected, they are
     * the customer's.
     */
    public function reject(int $complaintId, string $reason, int $now, int $user = 0): ProcessingResult
    {
        $reason = trim($reason);

        if ('' === $reason) {
            return ProcessingResult::refused('reasonMissing');
        }

        $orderId = 0;

        $result = $this->locked($complaintId, function (array $complaint, array $order) use ($reason, $now, $user, &$orderId): ProcessingResult {
            if (null !== ($refused = $this->undecidable($complaint))) {
                return $refused;
            }

            $orderId = (int) $order['id'];
            $complaintId = (int) $complaint['id'];

            foreach ($this->revocable($orderId, $complaintId, $this->rescinded($complaintId)) as $itemId) {
                if ($this->hasRevoked($orderId, $itemId)) {
                    return ProcessingResult::refused('revokedLine', [$this->creditLines->goods($orderId)[$itemId]['productName'] ?? '']);
                }
            }

            foreach (array_keys($this->rows($complaintId)) as $rowId) {
                $this->connection->update('tl_consho_complaint_item', ['rescindedQuantity' => '0', 'reduction' => null, 'replacementQuantity' => '0', 'restock' => 0, 'tstamp' => $now], ['id' => $rowId]);
                $this->stock?->forComplaintItem($rowId, false, $now, $user);
            }

            // No remedy: nothing was granted.
            $this->connection->update('tl_consho_complaint', [
                'state' => 'rejected',
                'remedy' => '',
                'rejectReason' => $reason,
                'decidedAt' => $now,
                'resolvedAt' => $now,
                'refundShipping' => '0',
                'tstamp' => $now,
            ], ['id' => $complaintId]);

            return ProcessingResult::done('rejected', [(string) $complaint['number']]);
        });

        if (!$result->done || null === $this->mail) {
            return $result;
        }

        $order = $this->orders->order($orderId);

        if (null !== $order && !$this->mail->complaintRejected($orderId, (string) $result->params[0], $reason, $this->links?->forMail($order) ?? '')) {
            return ProcessingResult::done('rejectedNoMail', $result->params);
        }

        return $result;
    }

    /**
     * Close (RK-6, A4): a repair or a complaint without a remedy is resolved
     * by hand. Final, no mail. A rescission or price reduction closes with its
     * refund instead; a replacement with the report of the last quantity it
     * names (U8: no partial replacement closed by hand), and an open
     * replacement draft is reported or deleted first.
     */
    public function close(int $complaintId, int $now): ProcessingResult
    {
        return $this->locked($complaintId, function (array $complaint) use ($now): ProcessingResult {
            if ('rejected' === $complaint['state'] || 'resolved' === $complaint['state']) {
                return ProcessingResult::refused('alreadyDecided');
            }

            if (\in_array($complaint['remedy'], self::REMEDIES, true)) {
                return ProcessingResult::refused('closedByRefund');
            }

            if ('replacement' === $complaint['remedy']) {
                return ProcessingResult::refused('closedByReplacement');
            }

            if (false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_delivery WHERE complaint = ? AND reportedAt IS NULL', [(int) $complaint['id']])) {
                return ProcessingResult::refused('replacementDraftOpen');
            }

            $this->connection->update('tl_consho_complaint', ['state' => 'resolved', 'resolvedAt' => $now, 'tstamp' => $now], ['id' => $complaint['id']]);

            return ProcessingResult::done('closed', [(string) $complaint['number']]);
        });
    }

    /**
     * Withdraw the decision (U9): the complaint is open again and offers the
     * choice anew. Only as long as nothing of it was carried out — no refund,
     * no reported replacement delivery, no return recorded, no line a
     * rescission made unusable (RK-E1). A replacement delivery still in draft
     * goes with the decision: it was filled from it.
     */
    public function withdraw(int $complaintId, int $now): ProcessingResult
    {
        return $this->locked($complaintId, function (array $complaint, array $order) use ($now): ProcessingResult {
            if (null !== ($refused = $this->unwithdrawable($complaint, (int) $order['id']))) {
                return $refused;
            }

            $complaintId = (int) $complaint['id'];

            foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_consho_delivery WHERE complaint = ? AND reportedAt IS NULL', [$complaintId]) as $deliveryId) {
                $this->connection->delete('tl_consho_delivery_item', ['pid' => (int) $deliveryId]);
                $this->connection->delete('tl_consho_delivery', ['id' => (int) $deliveryId]);
            }

            foreach (array_keys($this->rows($complaintId)) as $rowId) {
                $this->connection->update('tl_consho_complaint_item', ['rescindedQuantity' => '0', 'reduction' => null, 'replacementQuantity' => '0', 'tstamp' => $now], ['id' => $rowId]);
            }

            $this->connection->update('tl_consho_complaint', [
                'remedy' => '',
                'state' => 'open',
                'decidedAt' => null,
                'refundShipping' => '0',
                'tstamp' => $now,
            ], ['id' => $complaintId]);

            if ('rescission' === $complaint['remedy']) {
                // Block G: the key lines count as open again.
                $this->stock?->keysFreed((int) $order['id']);
            }

            return ProcessingResult::done('withdrawn', [(string) $complaint['number']]);
        });
    }

    /**
     * Whether the page offers "Entscheidung zurücknehmen" (U9).
     */
    public function withdrawable(int $complaintId): bool
    {
        $complaint = $this->connection->fetchAssociative('SELECT * FROM tl_consho_complaint WHERE id = ?', [$complaintId]);

        return false !== $complaint && null === $this->unwithdrawable($complaint, (int) $complaint['pid']);
    }

    /**
     * Why a decision can no longer be withdrawn, or null.
     *
     * @param array<string, mixed> $complaint
     */
    private function unwithdrawable(array $complaint, int $orderId): ProcessingResult|null
    {
        if ('rejected' === $complaint['state'] || 'resolved' === $complaint['state']) {
            return ProcessingResult::refused('alreadyDecided');
        }

        if ('' === (string) $complaint['remedy'] || null === $complaint['decidedAt']) {
            return ProcessingResult::refused('nothingToWithdraw');
        }

        if (null !== $complaint['refundedAt']) {
            return ProcessingResult::refused('alreadyRefunded');
        }

        $complaintId = (int) $complaint['id'];

        if (false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_delivery WHERE complaint = ? AND reportedAt IS NOT NULL', [$complaintId])) {
            return ProcessingResult::refused('replacementShipped');
        }

        if (null !== $complaint['returnReceivedAt'] || false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_complaint_item WHERE pid = ? AND returnedQuantity > 0', [$complaintId])) {
            return ProcessingResult::refused('returnAlready');
        }

        foreach ($this->revocable($orderId, $complaintId, $this->rescinded($complaintId)) as $itemId) {
            if ($this->hasRevoked($orderId, $itemId)) {
                return ProcessingResult::refused('revokedLine', [(string) ($this->creditLines->goods($orderId)[$itemId]['productName'] ?? '')]);
            }
        }

        return null;
    }

    /**
     * Why a complaint can no longer be decided anew, or null. A replacement
     * delivery binds the decision while it exists (RK-5, S1): it was filled
     * from the complained quantities.
     *
     * @param array<string, mixed> $complaint
     */
    private function undecidable(array $complaint): ProcessingResult|null
    {
        if ('rejected' === $complaint['state'] || 'resolved' === $complaint['state']) {
            return ProcessingResult::refused('alreadyDecided');
        }

        if (null !== $complaint['refundedAt']) {
            return ProcessingResult::refused('alreadyRefunded');
        }

        if (false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_delivery WHERE complaint = ?', [(int) $complaint['id']])) {
            return ProcessingResult::refused('replacementExists');
        }

        return null;
    }

    /**
     * The goods that came back (3.4): the date and per line how many — at
     * most the quantity complained about — and whether into stock, which is
     * off unless ticked (R6: defective goods).
     *
     * @param array<int, string> $quantities complaint item id => returned quantity
     * @param array<int, bool>   $restock    complaint item id => back into stock; a line not named keeps its last choice
     */
    public function recordReturn(int $complaintId, int $receivedAt, array $quantities, int $now, array $restock = [], int $user = 0): ProcessingResult
    {
        return $this->locked($complaintId, function (array $complaint) use ($receivedAt, $quantities, $now, $restock, $user): ProcessingResult {
            if ('rejected' === $complaint['state']) {
                return ProcessingResult::refused('alreadyDecided');
            }

            $rows = $this->rows((int) $complaint['id']);

            foreach ($quantities as $rowId => $quantity) {
                try {
                    $amount = BigDecimal::of('' !== trim($quantity) ? str_replace(',', '.', trim($quantity)) : '0');
                } catch (MathException) {
                    return ProcessingResult::refused('invalidQuantity');
                }

                if (!isset($rows[$rowId]) || $amount->isNegative() || $amount->isGreaterThan((string) $rows[$rowId]['quantity'])) {
                    return ProcessingResult::refused('invalidQuantity');
                }

                $this->connection->update('tl_consho_complaint_item', ['returnedQuantity' => (string) $amount, 'tstamp' => $now], ['id' => $rowId]);
            }

            // After the quantities: a correction books the difference. Goods
            // in for repair go back to the customer, never into stock (A1).
            if (null !== $this->stock) {
                $repair = 'repair' === $complaint['remedy'];

                foreach (array_keys($rows) as $rowId) {
                    $tick = !$repair && ($restock[$rowId] ?? (bool) $this->connection->fetchOne('SELECT restock FROM tl_consho_complaint_item WHERE id = ?', [$rowId]));
                    $this->stock->forComplaintItem($rowId, $tick, $now, $user);
                }
            }

            // An empty date keeps the one recorded.
            $this->connection->update('tl_consho_complaint', [
                'returnReceivedAt' => $receivedAt > 0 ? $receivedAt : $complaint['returnReceivedAt'],
                'tstamp' => $now,
            ], ['id' => $complaint['id']]);

            return ProcessingResult::done('returnRecorded', [(string) $complaint['number']]);
        });
    }

    /**
     * Book the refund of a decided rescission or price reduction: the
     * document, the money, the complaint resolved (L1).
     *
     * @param string      $shipping        delivery costs to refund with a rescission (L5), gross
     * @param bool       $throughProvider the payment method's part through its provider
     * @param string|null $confirmed       the provider's amount the operator confirmed (R5)
     * @param list<int>   $foreign         refunds made at the provider that are part of this one
     */
    public function refund(int $complaintId, string $shipping, int $now, bool $throughProvider = false, string|null $confirmed = null, array $foreign = [], string $by = ''): ProcessingResult
    {
        /** @var array{order: array<string, mixed>, number: string, booking: RefundBooking, remedy: string}|null $booked */
        $booked = null;

        $result = $this->locked($complaintId, function (array $complaint, array $order) use ($shipping, $now, $throughProvider, $confirmed, $foreign, $by, &$booked): ProcessingResult {
            if (!\in_array($complaint['remedy'], self::REMEDIES, true) || null === $complaint['decidedAt'] || 'rejected' === $complaint['state']) {
                return ProcessingResult::refused('notDecided');
            }

            if (null !== $complaint['refundedAt']) {
                return ProcessingResult::refused('alreadyRefunded');
            }

            $orderId = (int) $order['id'];
            $complaintId = (int) $complaint['id'];
            $rescission = 'rescission' === $complaint['remedy'];
            $digits = self::digits($order);
            $shippingLeft = $this->creditLines->shippingLeft($orderId);

            // A price reduction gives no delivery costs back: the goods stay
            // with the customer. Return postage is no part of the order; the
            // operator settles it outside the shop.
            $shippingAmount = self::amount($shipping);

            if (null === $shippingAmount || $shippingAmount->isNegative() || $shippingAmount->isGreaterThan($rescission ? $shippingLeft : BigDecimal::zero())) {
                return ProcessingResult::refused('invalidShipping', [(string) ($rescission ? $shippingLeft : BigDecimal::zero())->toScale($digits, RoundingMode::HalfUp)], [ProcessingResult::MONEY]);
            }

            $at = (int) $complaint['decidedAt'];

            if ($rescission) {
                $quantities = $this->rescinded($complaintId);
                $lines = $this->creditLines->lines($orderId, $quantities, $shippingAmount, null, $at);
                $credited = array_reduce($this->creditLines->credits($orderId, $quantities, null, $at), static fn (BigDecimal $sum, BigDecimal $credit): BigDecimal => $sum->plus($credit), BigDecimal::zero());
                $everything = $this->everything($orderId, $complaintId, $shippingAmount, $shippingLeft);
            } else {
                $amounts = $this->reductions($complaintId);
                $lines = $this->creditLines->reductionLines($orderId, $amounts);
                $credited = array_reduce($amounts, static fn (BigDecimal $sum, string $amount): BigDecimal => $sum->plus($amount), BigDecimal::zero());
                // L6: a price reduction never cancels the invoice.
                $everything = false;
            }

            $booking = $this->refunds->book(
                new RefundOccasion($order, (string) $complaint['number'], $lines, $everything, $credited, RefundOccasion::COMPLAINT),
                $now,
                $throughProvider,
                $confirmed,
                $foreign,
                $by,
            );

            if ($booking instanceof ProcessingResult) {
                return $booking;
            }

            $this->connection->update('tl_consho_complaint', [
                'refundShipping' => (string) $shippingAmount,
                'refundAmount' => $booking->amount,
                'refundedAt' => $now,
                'refundPayment' => $booking->payment,
                'refundDocument' => $booking->document,
                'state' => 'resolved',
                'resolvedAt' => $now,
                'tstamp' => $now,
            ], ['id' => $complaintId]);

            $booked = ['order' => $order, 'number' => (string) $complaint['number'], 'booking' => $booking, 'remedy' => (string) $complaint['remedy']];

            return ProcessingResult::done('refunded', [(string) $complaint['number']]);
        });

        if (null === $booked) {
            return $result;
        }

        // After the commit (R1): paid out, archived, the customer told.
        $booking = $booked['booking'];
        $documents = $this->refunds->finish($booking, $now);
        $this->statuses->advance((int) $booked['order']['id'], OrderEvent::WithdrawalRefunded, $now);

        if (null === $this->mail || null === $this->money) {
            return $result;
        }

        $order = $booked['order'];
        $language = (string) ($order['language'] ?? '');
        $format = fn (string $amount): string => BigDecimal::of($amount)->isPositive() ? $this->money->format($amount, $booking->currency, $language) : '';

        $sent = $this->mail->complaintRefundBooked(
            (int) $order['id'],
            $booked['number'],
            $booked['remedy'],
            $format($booking->payout),
            $this->money->format($booking->amount, $booking->currency, $language),
            $documents,
            $this->links?->forMail($order) ?? '',
            $format($booking->remaining),
            $format($booking->vouchers),
        );

        return $sent ? $result : ProcessingResult::done('refundedNoMail', [$booked['number']]);
    }

    /**
     * What the page proposes: per complaint line the most a rescission may
     * take and the most a reduction may take, the refund of the decision as
     * it stands, the delivery costs still refundable, and the ways back.
     *
     * @return array{lines: array<int, array{quantity: string, worth: string}>, goods: string, shippingLeft: string, vouchers: string, provider: string, providerMethods: list<string>, outside: list<array{id: int, reference: string, amount: string, currency: string}>}
     */
    public function proposal(int $complaintId): array
    {
        $complaint = $this->complaint($complaintId);
        $orderId = (int) ($complaint['pid'] ?? 0);
        $empty = ['lines' => [], 'goods' => '0', 'shippingLeft' => '0', 'vouchers' => '0', 'provider' => '0', 'providerMethods' => [], 'outside' => []];

        if (null === $complaint || $orderId < 1) {
            return $empty;
        }

        $order = $this->orders->order($orderId) ?? [];
        $goods = $this->creditLines->goods($orderId);
        $lines = [];

        foreach ($this->rows($complaintId) as $rowId => $row) {
            $itemId = (int) $row['orderItem'];

            if (!isset($goods[$itemId])) {
                continue;
            }

            $left = BigDecimal::of($goods[$itemId]['quantity'])->minus($this->returnedElsewhere($orderId, $complaintId, $itemId));

            $lines[$rowId] = [
                'quantity' => (string) BigDecimal::max(BigDecimal::zero(), BigDecimal::min($left, BigDecimal::of((string) $row['quantity']))),
                'worth' => (string) $this->creditLines->worth($orderId, $itemId, $complaintId),
            ];
        }

        $at = (int) ($complaint['decidedAt'] ?? 0);
        $credit = match ($complaint['remedy'] ?? '') {
            'rescission' => $this->creditLines->lines($orderId, $this->rescinded($complaintId), BigDecimal::zero(), null, $at),
            'reduction' => $this->creditLines->reductionLines($orderId, $this->reductions($complaintId)),
            default => [],
        };

        return [
            'lines' => $lines,
            'goods' => (string) $this->creditLines->total($credit, (string) ($order['currency'] ?? ''))->negated(),
            'shippingLeft' => (string) $this->creditLines->shippingLeft($orderId),
            'vouchers' => null === $this->vouchers ? '0' : (string) $this->vouchers->onVouchers($orderId, (string) $this->vouchers->paidTotal($orderId), $credit),
            'provider' => (string) array_reduce($this->payouts->refundable($orderId), static fn (BigDecimal $sum, string $left): BigDecimal => $sum->plus($left), BigDecimal::zero()),
            'providerMethods' => $this->payouts->methodTitles($orderId),
            'outside' => $this->payouts->openForeign($orderId),
        ];
    }

    /**
     * @param \Closure(array<string, mixed>, array<string, mixed>): ProcessingResult $action
     */
    private function locked(int $complaintId, \Closure $action): ProcessingResult
    {
        $orderId = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_complaint WHERE id = ?', [$complaintId]);

        if ($orderId < 1) {
            return ProcessingResult::refused('unknown');
        }

        $this->connection->beginTransaction();

        try {
            $order = $this->orders->lockOrder($orderId);
            $complaint = null === $order ? null : $this->complaint($complaintId);

            if (null === $order || null === $complaint) {
                $this->connection->commit();

                return ProcessingResult::refused('unknown');
            }

            $result = $action($complaint, $order);

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
    private function complaint(int $complaintId): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_complaint WHERE id = ?', [$complaintId]);

        return false === $row ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>> complaint item id => row
     */
    private function rows(int $complaintId): array
    {
        $rows = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_complaint_item WHERE pid = ? ORDER BY id', [$complaintId]) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }

    /**
     * @return array<int, string> order item id => rescinded quantity
     */
    private function rescinded(int $complaintId): array
    {
        return array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT orderItem, SUM(rescindedQuantity) FROM tl_consho_complaint_item WHERE pid = ? AND rescindedQuantity > 0 GROUP BY orderItem',
            [$complaintId],
        ));
    }

    /**
     * @return array<int, string> order item id => gross reduction
     */
    private function reductions(int $complaintId): array
    {
        return array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT orderItem, SUM(reduction) FROM tl_consho_complaint_item WHERE pid = ? AND reduction > 0 GROUP BY orderItem',
            [$complaintId],
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rows    complaint item id => row
     * @param array<int, string>               $decided complaint item id => quantity
     *
     * @return array<int, string> order item id => quantity
     */
    private function byOrderItem(array $rows, array $decided): array
    {
        $quantities = [];

        foreach ($decided as $rowId => $quantity) {
            $itemId = (int) $rows[$rowId]['orderItem'];
            $quantities[$itemId] = (string) BigDecimal::of($quantities[$itemId] ?? '0')->plus($quantity);
        }

        return $quantities;
    }

    /**
     * The service and digital lines (no subscription) that these quantities
     * of this complaint, with what went back otherwise, take back in full.
     *
     * @param array<int|string, string> $quantities order item id => rescinded quantity
     *
     * @return list<int> order item ids
     */
    private function revocable(int $orderId, int $complaintId, array $quantities): array
    {
        $goods = $this->creditLines->goods($orderId);
        $lines = [];

        foreach ($quantities as $itemId => $quantity) {
            $good = $goods[(int) $itemId] ?? null;

            if (null === $good
                || !\in_array($good['kind'] ?? '', [ProductKind::Service->value, ProductKind::Digital->value], true)
                || (int) ($good['subscriptionRule'] ?? 0) > 0
            ) {
                continue;
            }

            if ($this->returnedElsewhere($orderId, $complaintId, (int) $itemId)->plus($quantity)->isGreaterThanOrEqualTo($good['quantity'])) {
                $lines[] = (int) $itemId;
            }
        }

        return $lines;
    }

    private function hasRevoked(int $orderId, int $itemId): bool
    {
        return false !== $this->connection->fetchOne('SELECT 1 FROM tl_consho_entitlement WHERE pid = ? AND orderItem = ? AND revoked = 1', [$orderId, $itemId]);
    }

    /**
     * Revokes the entitlements of these order items; the delivery modules
     * are told after the commit (EntitlementRevocations).
     *
     * @param list<int> $itemIds
     */
    private function revoke(int $orderId, array $itemIds, int $now): void
    {
        if ([] === $itemIds) {
            return;
        }

        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_consho_entitlement WHERE pid = ? AND revoked = 0 AND orderItem IN (?)',
            [$orderId, $itemIds],
            [ParameterType::INTEGER, ArrayParameterType::INTEGER],
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
     * What of an order item went back other than by this complaint: accepted
     * withdrawals and the rescissions of the order's other complaints (L2).
     */
    private function returnedElsewhere(int $orderId, int $complaintId, int $itemId): BigDecimal
    {
        $withdrawn = $this->connection->fetchOne(
            "SELECT COALESCE(SUM(wi.acceptedQuantity), 0) FROM tl_consho_withdrawal_item wi
               JOIN tl_consho_withdrawal w ON w.id = wi.pid
              WHERE w.pid = ? AND w.state = 'accepted' AND wi.orderItem = ?",
            [$orderId, $itemId],
        );

        $rescinded = $this->connection->fetchOne(
            'SELECT COALESCE(SUM(ci.rescindedQuantity), 0) FROM tl_consho_complaint_item ci
               JOIN tl_consho_complaint c ON c.id = ci.pid
              WHERE c.pid = ? AND c.id <> ? AND ci.orderItem = ?',
            [$orderId, $complaintId, $itemId],
        );

        return BigDecimal::of((string) $withdrawn)->plus((string) $rescinded);
    }

    /**
     * Whether every goods line has gone back in full, with this complaint's
     * rescission.
     */
    private function complete(int $orderId, int $complaintId): bool
    {
        $mine = $this->rescinded($complaintId);

        foreach ($this->creditLines->goods($orderId) as $itemId => $good) {
            if ($this->returnedElsewhere($orderId, $complaintId, $itemId)->plus($mine[$itemId] ?? '0')->isLessThan($good['quantity'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * L6: everything going back in this one step — every goods line in full,
     * all delivery costs with it, and nothing else refunded or still to be
     * refunded — cancels the invoice, as at a withdrawal.
     */
    private function everything(int $orderId, int $complaintId, BigDecimal $shipping, BigDecimal $shippingLeft): bool
    {
        $earlier = (int) $this->connection->fetchOne(
            // An accepted withdrawal counts, refunded or not: not yet
            // refunded, it is booked on its own, and this step would cancel
            // the invoice in its stead.
            "SELECT COUNT(*) FROM tl_consho_withdrawal WHERE pid = ? AND state = 'accepted'",
            [$orderId],
        ) + (int) $this->connection->fetchOne(
            // So does another complaint's decided rescission or price
            // reduction, refunded or not: the grand total would give it back a
            // second time with its own refund.
            "SELECT COUNT(*) FROM tl_consho_complaint WHERE pid = ? AND id <> ?
                AND (refundedAt IS NOT NULL OR (remedy IN ('rescission', 'reduction') AND decidedAt IS NOT NULL AND state <> 'rejected'))",
            [$orderId, $complaintId],
        );

        return 0 === $earlier && $shipping->isEqualTo($shippingLeft) && $this->complete($orderId, $complaintId);
    }

    private static function amount(string $value): BigDecimal|null
    {
        try {
            return BigDecimal::of('' !== trim($value) ? str_replace(',', '.', trim($value)) : '0');
        } catch (MathException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $order
     */
    private static function digits(array $order): int
    {
        $currency = (string) ($order['currency'] ?? '');

        return '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
    }
}
