<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

/**
 * Remembers which findings somebody has taken note of.
 */
interface AcknowledgementStore
{
    /**
     * @return list<string> fingerprints
     */
    public function fingerprints(): array;

    public function acknowledge(ConfigurationIssue $issue, int $userId): void;
}
