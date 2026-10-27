<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Cart\CartLine;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Checkout\ShippingTaxSplitter;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAdjustmentOrigin;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentContext;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;

/**
 * Changing the payment method of a placed order (7.5, C23, C-10).
 *
 * Possible while nothing is paid and the operator's status does not hold the
 * order still (E3). An invoice never changes, so one that
 * stands is CANCELLED from its own snapshot and the new method gets a new one
 * where it issues on order — lückenlos in both sequences.
 *
 * ONCE per order (`paymentChangedAt`). 7.5 left it unlimited, as harmless in
 * the books; but a customer's back and forth would fill
 * two number ranges for nothing.
 *
 * ── What is recomputed, and from what ────────────────────────────────────
 *
 * The same questions the checkout asked, now asked of the ORDER instead of the
 * basket: is the method available (PaymentContext from the frozen addresses,
 * the member's groups and the goods plus shipping), and does it bring an
 * amount. An amount is split over the tax classes of the goods by the same
 * splitter the checkout uses — which is why the order lines carry their tax
 * class since C-10.
 * The RATE is not resolved again: each class takes the rate its goods were
 * frozen at, so the new line agrees with the lines beside it. Orders placed
 * before C-10 have no classes; there a method with an amount is not offered.
 *
 * A FEE is not offered at all (E-1): with a fixed tax class it would need
 * that class's rate for the order's country, and the order lines carry only
 * the rates of their own goods. Nor is cash on delivery (E-3): the order no
 * longer knows what its shipping method could do at the door.
 *
 * A follow-up order of a subscription (Abo-4) is offered every method, and
 * none with its amount: fee and discount alike would move the frozen price
 * (A9: allowed, without a fee).
 *
 * ── What happens after the commit ────────────────────────────────────────
 *
 * Archiving the new documents and telling the handlers of the cancelled
 * attempts. A handler's cancel() may call a provider; that is a side effect
 * a rollback cannot take back, so it runs only once nothing can roll back —
 * and it never undoes the change if it fails. The mail is the caller's (it
 * holds the view token for the link).
 *
 * start() of the new method is C-11 (E1): with prepayment it has nothing to
 * say that the order does not already hold.
 *
 * Not final: the order page's test stands a double in for it, as for the
 * basket repository.
 */
class PaymentChange
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly PaymentMethodRepositoryInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly ShippingTaxSplitter $splitter,
        private readonly PaymentStateCalculator $paymentStates,
        private readonly DocumentRepositoryInterface $documentRows,
        private readonly DocumentIssuerInterface $documents,
        private readonly DocumentArchiverInterface $archiver,
        private readonly WithdrawalCredit $credits,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param string $fallback the shop's fallback language, for method titles
     */
    public function options(int $orderId, string $fallback, int|null $now = null): PaymentChangeOptions
    {
        $order = $this->orders->order($orderId);

        if (null === $order) {
            return new PaymentChangeOptions(PaymentChangeBlock::MethodUnavailable);
        }

        return $this->evaluate($order, $this->orders->items($orderId), $fallback, $now ?? time());
    }

    /**
     * @throws PaymentChangeRefused
     */
    public function change(int $orderId, int $methodId, string $fallback, int $now): PaymentChanged
    {
        $this->connection->beginTransaction();

        try {
            // Locked first, and everything decided AFTER the lock: a payment
            // recorded in the back end a moment ago must stop this change.
            $order = $this->orders->lockOrder($orderId) ?? throw PaymentChangeRefused::because(PaymentChangeBlock::MethodUnavailable, $orderId);
            $options = $this->evaluate($order, $this->orders->items($orderId), $fallback, $now);
            $keepsTerm = $this->keepsTerm($orderId);

            if (null !== $options->block) {
                throw PaymentChangeRefused::because($options->block, $orderId);
            }

            $option = $options->find($methodId);

            if (null === $option || !$option->available) {
                throw PaymentChangeRefused::because(PaymentChangeBlock::MethodUnavailable, $orderId);
            }

            $attempts = $this->orders->openAttempts($orderId);

            foreach ($attempts as $attempt) {
                $this->orders->cancelAttempt($attempt->id, $now);
            }

            // From the invoice's snapshot, before the order is rewritten —
            // DocumentIssuer::cancel() never reads the order anyway.
            $invoiceId = $this->documentRows->findIdByOrderAndType($orderId, DocumentType::Invoice);
            $cancellationId = null === $invoiceId ? null : $this->documents->cancel($invoiceId);

            $this->orders->replacePaymentLines($orderId, $option->lines, $now);

            $totals = new OrderTotals($this->digitsOf($order['currency'] ?? ''));

            foreach ($this->orders->items($orderId) as $item) {
                $totals->add(BigDecimal::of($item['lineTotal'])->minus($item['taxAmount']), $item['lineTotal']);
            }

            $fields = [
                'tstamp' => $now,
                'paymentChangedAt' => $now,
                'paymentMethod' => $option->method->id,
                'paymentMethodTitle' => $option->method->title,
                // Frozen like at the purchase (C-9), in the order's language:
                // forShop() was asked in it.
                'paymentInstructions' => \is_scalar($option->method->config['instructions'] ?? null) ? (string) $option->method->config['instructions'] : '',
                'netTotal' => $totals->net(),
                'taxTotal' => $totals->tax(),
                'grandTotal' => $totals->gross(),
                // The old invoice's term goes with it (E): a
                // new one writes its own, a method that issues later none —
                // otherwise a cancelled invoice would turn up as overdue.
                'paymentDueAt' => 0,
            ];

            // An order of a subscription keeps its term (Abo-5): without it, it could never fall overdue, and a switch to
            // prepayment would lift the rest without a payment (P39). A new
            // invoice still writes its own term below.
            if ($keepsTerm) {
                unset($fields['paymentDueAt']);
            }

            $this->orders->updateOrder($orderId, $fields);

            $newInvoiceId = null;
            $trigger = $this->handlers->get($option->method->type)->documentTrigger($option->method->config);

            // A method that issues on shipment issues NOW when the delivery is
            // already reported — DeliveryReported will not come again, and the
            // order would keep the cancellation and no invoice. A follow-up
            // order of a subscription is always delivered (Abo-4, P27).
            if (DocumentTrigger::OnOrder === $trigger || (DocumentTrigger::OnShipment === $trigger && $this->orders->hasReportedDelivery($orderId))) {
                $newInvoiceId = $this->documents->issueOnce($orderId, (int) $order['shop'], DocumentType::Invoice);
            }

            // The new invoice writes its own term — 0 for one without a term,
            // a prepayment invoice among them. An order of a subscription
            // then gets its old term back: otherwise the switch to prepayment
            // would still lift the rest without a payment (Abo-5). A new invoice WITH a term keeps it.
            $oldDueAt = (int) ($order['paymentDueAt'] ?? 0);

            if ($keepsTerm && $oldDueAt > 0 && 0 === (int) ($this->orders->order($orderId)['paymentDueAt'] ?? 0)) {
                $this->orders->updateOrder($orderId, ['paymentDueAt' => $oldDueAt]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        foreach ([$cancellationId, $newInvoiceId] as $documentId) {
            if (null !== $documentId) {
                $this->archiver->archive($documentId);
            }
        }

        $this->tellHandlers($attempts, $options);

        return new PaymentChanged($orderId, $cancellationId, $newInvoiceId);
    }

    /**
     * @param array<string, string>       $order
     * @param list<array<string, string>> $items
     */
    private function evaluate(array $order, array $items, string $fallback, int $now): PaymentChangeOptions
    {
        $block = $this->blockOf($order, $now);

        if (null !== $block) {
            return new PaymentChangeOptions($block);
        }

        $shopId = (int) $order['shop'];
        $digits = $this->digitsOf($order['currency'] ?? '');
        $goods = array_values(array_filter($items, static fn (array $item): bool => OrderLineType::stored($item['lineType'] ?? null)->isGoods()));

        $billingCountry = strtoupper(trim($order['billingCountry'] ?? ''));
        $deliveryCountry = strtoupper(trim($order['shippingCountry'] ?? ''));

        $context = new PaymentContext(
            $shopId,
            (string) ($order['currency'] ?? ''),
            $billingCountry,
            '' !== $deliveryCountry ? $deliveryCountry : $billingCountry,
            $this->orders->memberGroups((int) ($order['member'] ?? 0)),
            // The goods plus shipping, as the checkout judges a limit (C-5) —
            // less a cart rule's discount since block H, as the checkout does.
            $this->netOf($items, [OrderLineType::Product, OrderLineType::Shipping, OrderLineType::Discount]),
            // No shipping capabilities: the order keeps the shipping method's
            // title, not what it could do at the door then. So cash on
            // delivery (E-3) is not offered as a change — it would promise a
            // collection nobody has checked.
            null,
            // E5b: as at the purchase, from the kinds frozen on the lines.
            [] !== array_filter($goods, static fn (array $item): bool => \in_array(ProductKind::stored((string) ($item['kind'] ?? '')), [ProductKind::Digital, ProductKind::Voucher], true)),
        );

        $options = [];
        $followUp = (int) ($order['subscription'] ?? 0) > 0;

        foreach ($this->methods->forShop($shopId, (string) ($order['language'] ?? ''), $fallback) as $method) {
            if (!$this->handlers->has($method->type)) {
                continue;
            }

            if ($method->id === (int) ($order['paymentMethod'] ?? 0)) {
                $options[] = new PaymentChangeOption($method, false, true);

                continue;
            }

            $handler = $this->handlers->get($method->type);

            if (!$handler->isAvailable($context, $method->config)) {
                $options[] = new PaymentChangeOption($method, false, false);

                continue;
            }

            // A follow-up order of a subscription keeps its frozen price (A9):
            // the new method comes without its fee or discount. Its first order paid the
            // price of the contract, not of a payment method.
            $adjustment = $followUp ? null : $handler->adjustment($context, $method->config);

            if (null === $adjustment) {
                $options[] = new PaymentChangeOption($method, true, false);

                continue;
            }

            // A fee stays unavailable here after E-1 too: with a fixed tax
            // class it would need that class's rate for the order's country,
            // and the order lines carry only the rates of their own goods.
            $lines = PaymentAdjustmentOrigin::Fee === $adjustment->origin ? null :$this->discountLines($method, $adjustment->net, $goods, $digits, strtoupper('' !== (string) ($order['shippingCountry'] ?? '') ? (string) $order['shippingCountry'] : (string) ($order['billingCountry'] ?? '')));

            $options[] = null === $lines
                ? new PaymentChangeOption($method, false, false)
                : new PaymentChangeOption($method, true, false, $this->grossOf($lines, $digits), $lines);
        }

        return new PaymentChangeOptions(null, $options);
    }

    /**
     * Whether the order keeps its term through a switch and is not switched
     * once overdue: an order of a subscription (Abo-5, P39), and since the
     * Nachlese one whose digital goods can rest
     * (PurchaseDelinquency) — a switch to prepayment would otherwise lift
     * the rest without a payment.
     */
    private function keepsTerm(int $orderId): bool
    {
        return $this->orders->belongsToSubscription($orderId)
            || $this->orders->hasOneTimeEntitlements($orderId);
    }

    /**
     * @param array<string, string> $order
     */
    private function blockOf(array $order, int $now): PaymentChangeBlock|null
    {
        [$credited, $withdrawn] = $this->credits->of((int) $order['id']);

        // First: after a withdrawal nothing below applies any more (D-3).
        if ([] !== $withdrawn || !BigDecimal::of($credited)->isZero()) {
            return PaymentChangeBlock::AfterWithdrawal;
        }

        $state = $this->paymentStates->calculate(
            OrderBalance::claimOfRow($order),
            $this->orders->paymentAmounts((int) $order['id']),
        );

        if (PaymentState::Open !== $state) {
            return PaymentChangeBlock::NotOpen;
        }

        // Once per order (C-10). Before the status: it is the
        // reason the customer can act on least, and the more specific one.
        if ((int) ($order['paymentChangedAt'] ?? 0) > 0) {
            return PaymentChangeBlock::AlreadyChanged;
        }

        $status = (int) ($order['orderStatus'] ?? 0);

        if ($status > 0 && $this->orders->statusHolds($status)) {
            return PaymentChangeBlock::StatusLocks;
        }

        // Last: the reason is a date, and every other reason says more.
        $dueAt = (int) ($order['paymentDueAt'] ?? 0);

        if ($dueAt > 0 && $dueAt < $now && $this->keepsTerm((int) $order['id'])) {
            return PaymentChangeBlock::Overdue;
        }

        return null;
    }

    /**
     * The discount lines a method would put on the order, split over the tax
     * classes of the goods like at the purchase — or null when the order
     * cannot say which classes its goods are in (placed before C-10).
     *
     * @param list<array<string, string>> $goods
     *
     * @return list<array<string, string|int>>|null
     */
    private function discountLines(PaymentMethod $method, string $net, array $goods, int $digits, string $deliveryCountry = ''): array|null
    {
        /*
         * Block H: a discount reduces each supply at its own rate, so
         * it is split per class AND country of the goods (DL4: a service
         * carries the shop's country, goods the delivery's), and each line
         * takes the rate those goods carry — no resolver needed. Until then
         * it was taxed in the delivery country like shipping, with the open
         * point of a class held only by another country's lines
         * (Block DL); that point is gone with it. The
         * per-class rates below remain the fallback for a line without a
         * country (an order from before DL4).
         */
        $rates = [];
        $foreignRates = [];
        $countryRates = [];
        $cartLines = [];
        $reverseCharge = false;

        foreach ($goods as $item) {
            // A voucher takes no share of the discount (WG8), as at the purchase.
            if (ProductKind::Voucher === ProductKind::stored((string) ($item['kind'] ?? ''))) {
                continue;
            }

            $class = (int) ($item['taxClass'] ?? 0);

            if ($class < 1) {
                return null;
            }

            $country = strtoupper((string) ($item['taxCountry'] ?? ''));

            if ('' === $country || '' === $deliveryCountry || $country === $deliveryCountry) {
                $rates[$class] ??= (string) $item['taxRate'];
            } else {
                $foreignRates[$class] ??= (string) $item['taxRate'];
            }

            $reverseCharge = $reverseCharge || PriceSource::ReverseCharge->value === ($item['priceSource'] ?? '');
            $lineNet = (string) BigDecimal::of($item['lineTotal'])->minus($item['taxAmount']);
            $cartLines[] = new CartLine(0, 0, 0, (string) $item['quantity'], (string) $item['unitPrice'], $lineNet, (string) $item['lineTotal'], $class, taxCountry: '' !== $country ? $country : $deliveryCountry);
            // Block H: the rate of this class in this country, as the goods carry it.
            $countryRates[$class.'|'.('' !== $country ? $country : $deliveryCountry)] ??= (string) $item['taxRate'];
        }

        if ([] === $cartLines) {
            return null;
        }

        $rates += $foreignRates;
        $lines = [];

        foreach ($this->splitter->split($net, ShippingTaxMode::Proportional, 0, $cartLines, $digits, true) as $share) {
            // The sign goes on here, once — PaymentAdjustment is always
            // positive and a discount is the only origin that gets this far.
            $lineNet = BigDecimal::of($share->net)->negated()->toScale(4, RoundingMode::HalfUp);
            $rate = $countryRates[$share->taxClassId.'|'.$share->taxCountry] ?? $rates[$share->taxClassId] ?? '0';
            $lineTax = $lineNet
                ->multipliedBy(BigDecimal::of($rate))
                ->dividedBy('100', 8, RoundingMode::HalfUp)
                ->toScale(4, RoundingMode::HalfUp)
            ;

            $lines[] = [
                'lineType' => OrderLineType::PaymentDiscount->value,
                'product' => 0,
                'source' => $method->id,
                'productName' => $method->title,
                'sku' => '',
                'quantity' => '1.0000',
                'unitPrice' => (string) $lineNet,
                'taxClass' => $share->taxClassId,
                'taxRate' => (string) BigDecimal::of($rate)->toScale(4, RoundingMode::HalfUp),
                'taxCountry' => '' !== $share->taxCountry ? $share->taxCountry : $deliveryCountry,
                // Block R (R4): where the order went, as on its other lines.
                'destinationCountry' => $deliveryCountry,
                'taxAmount' => (string) $lineTax,
                'lineTotal' => (string) $lineNet->plus($lineTax),
                'priceSource' => $reverseCharge ? PriceSource::ReverseCharge->value : PriceSource::List->value,
                'customerGroup' => 0,
            ];
        }

        return $lines;
    }

    /**
     * @param list<array<string, string>> $items
     * @param list<OrderLineType>         $types
     */
    private function netOf(array $items, array $types): string
    {
        $net = BigDecimal::zero();

        foreach ($items as $item) {
            if (\in_array(OrderLineType::stored($item['lineType'] ?? null), $types, true)) {
                $net = $net->plus(BigDecimal::of($item['lineTotal'])->minus($item['taxAmount']));
            }
        }

        return (string) $net->toScale(4, RoundingMode::HalfUp);
    }

    /**
     * @param list<array<string, string|int>> $lines
     */
    private function grossOf(array $lines, int $digits): string
    {
        $totals = new OrderTotals($digits);

        foreach ($lines as $line) {
            $totals->add((string) $line['unitPrice'], (string) $line['lineTotal']);
        }

        return $totals->gross();
    }

    /**
     * @param list<PaymentAttempt> $attempts
     */
    private function tellHandlers(array $attempts, PaymentChangeOptions $options): void
    {
        foreach ($attempts as $attempt) {
            if (!$this->handlers->has($attempt->methodType)) {
                continue;
            }

            try {
                $this->handlers->get($attempt->methodType)->cancel($attempt, $options->find($attempt->methodId)?->method->config ?? []);
            } catch (\Throwable $e) {
                // The change stands; the provider is told what it can be told.
                $this->logger->error(\sprintf('Cancelling payment attempt %d with its provider failed: %s', $attempt->id, $e->getMessage()), ['exception' => $e]);
            }
        }
    }

    private function digitsOf(string $currency): int
    {
        return '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
    }
}
