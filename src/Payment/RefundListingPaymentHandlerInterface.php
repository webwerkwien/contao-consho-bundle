<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Webwerkwien\ContaoConshoBundle\Payment\Refund\PaymentRefund;

/**
 * A refunding handler that can also list the refunds its provider holds for
 * a payment (spec 9, N3). Optional.
 *
 * "Von Hand erledigt" then offers them for selection: the operator who paid
 * back in the provider's dashboard points at the refund that was made, and
 * Consho stores its ID — the one key a later notification about it is
 * matched by. Without it, the mark is a note without that link, as before.
 */
interface RefundListingPaymentHandlerInterface extends RefundingPaymentHandlerInterface
{
    /**
     * Every refund of the payment $refund pays back
     * ($refund->paymentReference), Consho's own included — the core leaves
     * out what it already knows. Throw when the provider cannot be asked;
     * "Von Hand erledigt" then works without the selection.
     *
     * @param array<string, mixed> $config
     *
     * @return list<ProviderEvent> of kind Refund
     */
    public function refunds(PaymentRefund $refund, array $config): array;
}
