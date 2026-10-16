<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * Thrown by handleNotification() when a notification cannot be shown to come
 * from the provider — a missing or wrong signature, or a message signed for
 * another attempt. The notification route answers 400 and writes nothing
 * (spec 12).
 *
 * An exception rather than a fourth NotificationResult: `ignored` means "a
 * genuine message that concerns no payment" and is answered 200, so that the
 * provider stops resending it. A forged one must not get that answer.
 */
final class NotificationRejected extends \RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
