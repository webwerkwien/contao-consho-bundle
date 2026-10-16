<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

final class DeliverabilityReport
{
    /**
     * @param bool|null                  $hasDkim            null means "not verifiable", not "absent"
     * @param string|null                $authenticatedDomain null when nothing was authenticated, or the account is not an address
     * @param list<DeliverabilityProblem> $problems
     */
    public function __construct(
        public readonly bool $hasSpf,
        public readonly bool|null $hasDkim,
        public readonly bool $hasDmarc,
        public readonly bool $isAligned,
        public readonly array $problems,
        public readonly string|null $senderDomain = null,
        public readonly string|null $authenticatedDomain = null,
    ) {
    }

    public function isClean(): bool
    {
        return [] === $this->problems;
    }
}
