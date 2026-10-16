<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dev;

use Psr\Log\AbstractLogger;

/**
 * Keeps the SQL a connection executes, for EXPLAIN (plan B-9).
 *
 * Hung into DBAL's logging middleware on a connection of its own, so the
 * benchmark can EXPLAIN exactly what ProductQuery sent — without ProductQuery
 * exposing its SQL. DBAL 4 logs "Executing statement" with sql, params and
 * types in the context, and "Executing query" with sql only.
 */
final class QueryRecorder extends AbstractLogger
{
    /**
     * @var list<array{sql: string, params: list<mixed>, types: list<mixed>}>
     */
    private array $queries = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!isset($context['sql']) || !\is_string($context['sql'])) {
            return;
        }

        $this->queries[] = [
            'sql' => $context['sql'],
            'params' => array_values((array) ($context['params'] ?? [])),
            'types' => array_values((array) ($context['types'] ?? [])),
        ];
    }

    public function reset(): void
    {
        $this->queries = [];
    }

    /**
     * @return list<array{sql: string, params: list<mixed>, types: list<mixed>}>
     */
    public function queries(): array
    {
        return $this->queries;
    }
}
