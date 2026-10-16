<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * A WHERE fragment and the parameters it needs.
 *
 * Deliberately not a finished result: a criterion that returned a list of IDs
 * would have to be joined back in PHP, and a catalogue is exactly the place
 * where that stops working. The fragment goes into the caller's query, and the
 * database does the rest.
 */
final class SqlCondition
{
    /**
     * @param array<string, int|string> $params
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
    ) {
    }
}
