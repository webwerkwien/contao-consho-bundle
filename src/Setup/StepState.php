<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;

/**
 * One step of one shop: done, open (something in it stops the checkout) or
 * "attention" (warnings that do not stop it, such as a shipping method
 * without a price in one currency).
 */
final class StepState
{
    public const DONE = 'done';

    public const OPEN = 'open';

    public const ATTENTION = 'attention';

    /**
     * @param list<ConfigurationIssue> $issues
     */
    public function __construct(
        public readonly SetupStep $step,
        public readonly string $status,
        public readonly array $issues,
    ) {
    }
}
