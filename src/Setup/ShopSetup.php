<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;

/**
 * The setup state of one shop configuration.
 */
final class ShopSetup
{
    /**
     * @param list<array{id: int, title: string, language: string}> $roots the published start points naming this shop
     * @param list<StepState>                                          $steps
     * @param list<ConfigurationIssue>                                 $others warnings of this shop that belong to no step — operation, not setup
     */
    public function __construct(
        public readonly int $shopId,
        public readonly string $title,
        public readonly bool $ready,
        public readonly array $roots,
        public readonly array $steps,
        public readonly array $others,
    ) {
    }
}
