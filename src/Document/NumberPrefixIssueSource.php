<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Two document number ranges with the same prefix issue the same number
 * twice (spec C, open points, acceptance C-12): "Testshop 2" was a copy of
 * the test shop with the prefix "RE-", and RE-00005 would have existed in
 * both. The same within one shop: an invoice and a cancellation both
 * numbered "RE-00001".
 *
 * A note, not a warning — like DuplicateVatId the other way round: two
 * companies may well use the same prefix, and the core cannot tell whether
 * two shops are one company. Order numbers are left out: they are unique
 * per shop by design and are no tax documents.
 *
 * Compared trimmed and case-insensitive, an empty prefix included: "re-"
 * and "RE-" are not two sequences anyone could tell apart on paper. The
 * padding is not compared — RE-0001 and RE-00001 differ only until 10000.
 */
final class NumberPrefixIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT r.pid, r.type, r.prefix, s.title
               FROM tl_consho_number_range r
               JOIN tl_consho_shop s ON s.id = r.pid
              WHERE r.type <> 'order'
              ORDER BY s.id, r.id",
        );

        $byPrefix = [];

        foreach ($rows as $row) {
            $byPrefix[mb_strtolower(trim((string) $row['prefix']))][] = $row;
        }

        $issues = [];

        foreach ($byPrefix as $ranges) {
            if (\count($ranges) < 2) {
                continue;
            }

            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::NumberPrefixShared,
                [
                    'prefix' => trim((string) $ranges[0]['prefix']),
                    'ranges' => implode(', ', array_map(
                        static fn (array $range): string => \sprintf(
                            '%s (%s)',
                            $range['title'],
                            $GLOBALS['TL_LANG']['CONSHO']['numberRangeTypes'][$range['type']] ?? $range['type'],
                        ),
                        $ranges,
                    )),
                ],
                (int) $ranges[0]['pid'],
            );
        }

        return $issues;
    }
}
