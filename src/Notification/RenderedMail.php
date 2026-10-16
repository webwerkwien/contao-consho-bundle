<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

final class RenderedMail
{
    public function __construct(
        public readonly string $subject,
        public readonly string $body,
    ) {
    }
}
