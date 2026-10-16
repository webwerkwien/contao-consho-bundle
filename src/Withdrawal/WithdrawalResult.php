<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

final class WithdrawalResult
{
    public function __construct(
        public readonly WithdrawalOutcome $outcome,
        public readonly int|null $id = null,
        public readonly string $number = '',
        public readonly int|null $receivedAt = null,
        public readonly bool $confirmed = false,
    ) {
    }
}
