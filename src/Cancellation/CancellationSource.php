<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

/**
 * The two ways in: "Verträge hier kündigen", open to anyone with the
 * subscription number and its e-mail address (P58), and "Meine Abos" in the
 * customer account (P60) — and the operator's own ordinary cancellation in
 * the back end (Abo-9, P128) — and the end a price offer brings: refused, or
 * left unanswered by a consumer (Abo-10, P121, P122).
 */
enum CancellationSource: string
{
    case Form = 'form';
    case Account = 'account';
    case Operator = 'operator';
    case PriceChange = 'priceChange';
}
