<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * A payment handler whose provider appends query parameters of its own names
 * to the return address — Stripe's `session_id`, for one (Nachlese K3,
 * spec C).
 *
 * Optional, like RecurringPaymentHandlerInterface, and implemented by the
 * handler next to PaymentHandlerInterface — only handlers are asked, through
 * the tag `consho.payment_handler`. Contao's shared cache
 * strips every parameter an allow list (QUERY_PARAMS_ALLOW_LIST) does not
 * match, and a stripped return reads as "nothing received yet". The finding
 * noticeParamStripped names Consho's own parameters; a handler implementing
 * this adds its provider's to the same list, so the operator learns about
 * them before the first customer comes back without them.
 *
 * Parameters starting with `consho_` need not be listed: the recommended
 * entry "consho_.*" covers them, and NoticeParamIssueSource checks every
 * name against the list either way.
 */
interface ReturnParametersInterface
{
    /**
     * @return list<string> the parameter names exactly as the provider spells them
     */
    public static function returnParameters(): array;
}
