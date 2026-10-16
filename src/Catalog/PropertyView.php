<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One property group of a product with the options it carries, labelled in the
 * page's language — "Sorte: Kräuterbitter".
 */
final class PropertyView
{
    /**
     * @param list<string> $values option labels, in the group's option order
     */
    public function __construct(
        public readonly int $groupId,
        public readonly string $label,
        public readonly array $values,
    ) {
    }
}
