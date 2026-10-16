<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The order may no longer be changed, and the message says which of the three
 * reasons applies — "locked" alone leaves the operator guessing what to undo.
 */
final class OrderIsLocked extends \RuntimeException
{
    public static function becauseOfPayment(): self
    {
        return new self('The order is locked: a payment has been recorded.');
    }

    public static function becauseOfDocument(): self
    {
        return new self('The order is locked: a document has been issued.');
    }

    public static function becauseOfStatus(): self
    {
        return new self('The order is locked: its status locks changes.');
    }
}
