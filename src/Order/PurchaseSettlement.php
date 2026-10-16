<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutCalculator;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;

/**
 * An order that owes nothing at the purchase click is paid by it (block
 * Wertgutscheine, WG15).
 *
 * PaymentRecorded settles an order when a payment comes in, and only one that
 * owes something — a payment of 0 never comes. So an order of 0 € (an e-book
 * with a 100 % code, since block H) never got `paymentSettledAt`: no invoice
 * "on payment", and its digital lines waited forever (DigitalRelease asks for
 * the settlement). Vouchers make the case common: one that covers the whole
 * order leaves nothing for any payment method to collect.
 *
 * Inside the purchase transaction, after every line and every payment the
 * click writes: what PaymentRecorded does for the money, minus the mail
 * "Zahlung erhalten" — nobody paid, and the confirmation is what the buyer
 * gets. The digital lines follow after the confirmation, where the checkout
 * releases what is due anyway.
 */
class PurchaseSettlement
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DocumentIssuerInterface $documents,
        private readonly PaymentHandlers $handlers,
        private readonly OrderStatusAdvancer $statuses,
    ) {
    }

    /**
     * @return int|null the invoice issued now, for the archive after the commit
     */
    public function settleIfNothingOpen(int $orderId, int $shopId, PaymentMethod $method, int $now): int|null
    {
        $grandTotal = (string) $this->connection->fetchOne('SELECT grandTotal FROM tl_consho_order WHERE id = ?', [$orderId]);
        $paid = (string) $this->connection->fetchOne('SELECT COALESCE(SUM(amount), 0) FROM tl_consho_payment WHERE pid = ?', [$orderId]);

        if (!BigDecimal::of('' === $grandTotal ? '0' : $grandTotal)->minus($paid)->isZero()) {
            return null;
        }

        $this->connection->update('tl_consho_order', ['paymentSettledAt' => $now], ['id' => $orderId]);

        $invoiceId = null;

        /*
         * Paid "by voucher" (E1) the invoice comes now: the vouchers are the
         * payment, and no handler will ever report another.
         */
        $onPayment = CheckoutCalculator::PAYMENT_BY_VOUCHER === $method->id
            || ($this->handlers->has($method->type) && DocumentTrigger::OnPayment === $this->handlers->get($method->type)->documentTrigger($method->config));

        if ($onPayment && !$this->documents->hasDocument($orderId, DocumentType::Invoice)) {
            $invoiceId = $this->documents->issueOnce($orderId, $shopId, DocumentType::Invoice);
        }

        $this->statuses->advance($orderId, OrderEvent::PaymentSettled, $now);

        return $invoiceId;
    }
}
