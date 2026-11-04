<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

/**
 * A refund made at the provider that was chosen for a refund is no longer
 * open, or no longer fits, once the order is locked. Its money must not move
 * to another way — that would pay it back twice — so the booking stops and
 * the caller rolls back.
 */
final class RefundStateChangedException extends \RuntimeException
{
}
