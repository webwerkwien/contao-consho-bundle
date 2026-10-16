<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Token;

/**
 * A token that let somebody in: which order, and whether acting through it
 * proves the mailbox (C-10 — only a token that went out by mail does).
 */
final class ValidToken
{
    public function __construct(
        public readonly int $orderId,
        public readonly bool $confirmsEmail,
    ) {
    }
}
