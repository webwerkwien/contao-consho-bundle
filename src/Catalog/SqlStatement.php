<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;

/**
 * A whole SELECT with its parameters and their types.
 *
 * SqlCondition is a WHERE fragment for somebody else's query; this is a query
 * of its own that a caller runs as it is or wraps — INSERT … SELECT for the
 * projection, COUNT(*) for the diagnosis, a plain fetch for verify.
 */
final class SqlStatement
{
    /**
     * @param array<string, mixed>                                   $params
     * @param array<string, ArrayParameterType|ParameterType|string> $types
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $params = [],
        public readonly array $types = [],
    ) {
    }
}
