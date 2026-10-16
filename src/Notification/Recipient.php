<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

/**
 * One addressee of one notification, with the language it goes out in.
 *
 * The language belongs HERE and not to the send, because a single event can
 * have recipients in different ones: the customer's confirmation follows the
 * order, the operator's copy follows the shop.
 */
final class Recipient
{
    public function __construct(
        public readonly string $email,
        public readonly string $name,
        public readonly string $language,
    ) {
    }
}
