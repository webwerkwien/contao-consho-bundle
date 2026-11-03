<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * What a provider reports after a payment succeeded (spec 9).
 */
enum ProviderEventKind: string
{
    /** The customer's bank took the money back. */
    case Chargeback = 'chargeback';

    /** A refund made at the provider, not through Consho. */
    case Refund = 'refund';
}
