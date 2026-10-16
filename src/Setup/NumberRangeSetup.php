<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;

/**
 * "Fehlende Nummernkreise anlegen" (block I, I1).
 *
 * Creates exactly the types the findings name (`typeKeys` of
 * numberRangeMissing and refundRangeMissing) — which ranges a shop needs is
 * decided there, by the payment methods' document triggers, and a second
 * rule here would drift from it.
 *
 * Plain inserts: tl_consho_number_range carries no callbacks, and a range is a
 * row with a counter at 0. Adds, never changes — the unique key (pid, type)
 * stops a double click from creating one twice.
 *
 * Prefixes are a suggestion, editable in the mask. One already used by
 * another range (trimmed, case-insensitive, as NumberPrefixIssueSource
 * compares them) gets the shop id in front, so a second shop does not start
 * with a "shared prefix" note. Order numbers are left out of that comparison
 * there, and so here.
 */
final class NumberRangeSetup
{
    public const PREFIXES = [
        'order' => 'B-',
        'invoice' => 'RE-',
        'cancellation' => 'ST-',
        // Rechnungskorrektur, not Gutschrift (D-3, F2) — so not "GS-" either.
        'creditNote' => 'RK-',
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The types the findings ask for, in the order of PREFIXES.
     *
     * @param list<ConfigurationIssue> $issues
     *
     * @return list<string>
     */
    public static function missing(int $shopId, array $issues): array
    {
        $types = [];

        foreach ($issues as $issue) {
            if ($issue->shopId !== $shopId || !\in_array($issue->finding, [ConfigurationFinding::NumberRangeMissing, ConfigurationFinding::RefundRangeMissing], true)) {
                continue;
            }

            foreach (explode(',', (string) ($issue->context['typeKeys'] ?? '')) as $type) {
                if (isset(self::PREFIXES[$type])) {
                    $types[$type] = true;
                }
            }
        }

        return array_values(array_filter(array_keys(self::PREFIXES), static fn (string $type): bool => isset($types[$type])));
    }

    /**
     * @param list<string> $types
     *
     * @return array<string, string> type => prefix, of the ranges created
     */
    public function create(int $shopId, array $types, int $now): array
    {
        $created = [];

        $this->connection->transactional(function () use ($shopId, $types, $now, &$created): void {
            $have = $this->connection->fetchFirstColumn('SELECT type FROM tl_consho_number_range WHERE pid = ?', [$shopId]);
            $used = array_map(
                static fn (mixed $prefix): string => mb_strtolower(trim((string) $prefix)),
                $this->connection->fetchFirstColumn("SELECT prefix FROM tl_consho_number_range WHERE type <> 'order'"),
            );

            foreach ($types as $type) {
                if (!isset(self::PREFIXES[$type]) || \in_array($type, $have, true)) {
                    continue;
                }

                $prefix = self::PREFIXES[$type];

                if ('order' !== $type && \in_array(mb_strtolower($prefix), $used, true)) {
                    $prefix = $shopId.$prefix;
                }

                $this->connection->insert('tl_consho_number_range', [
                    'pid' => $shopId,
                    'tstamp' => $now,
                    'type' => $type,
                    'prefix' => $prefix,
                    'padding' => 5,
                    'current' => 0,
                ]);

                $used[] = mb_strtolower($prefix);
                $created[$type] = $prefix;
            }
        });

        return $created;
    }
}
