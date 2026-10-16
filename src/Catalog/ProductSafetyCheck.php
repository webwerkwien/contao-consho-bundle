<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;

/**
 * Finds the product safety details the GPSR requires and the shop does not have.
 *
 * A pure function over values handed in, like ConfigurationCheck in A-9 — the
 * counting and joining happens in the source behind it, so these rules can be
 * read and tested without a database.
 *
 * The findings go onto the back end start page through the same path as the
 * configuration ones, because from the operator's side they answer the same
 * question: what is wrong with this shop right now.
 *
 * ── Two different shapes of report, on purpose ────────────────────────────
 *
 * Products are counted, manufacturers are named. A catalogue import can leave
 * hundreds of products without a manufacturer, and a start page listing them
 * would be unusable. Manufacturers are few, and naming the one that lacks an
 * address is the difference between a task and a chore.
 */
final class ProductSafetyCheck
{
    /**
     * @return list<ConfigurationIssue>
     */
    public function fromProductsWithoutManufacturer(int $count): array
    {
        if ($count < 1) {
            return [];
        }

        return [
            new ConfigurationIssue(
                ConfigurationFinding::ProductManufacturerMissing,
                ['count' => (string) $count],
            ),
        ];
    }

    /**
     * Two products carrying one number.
     *
     * Reported rather than forbidden. A unique index would be the trap from
     * A-10 again — Contao inserts empty rows when a record is created — and it
     * would also be wrong: a shop may legitimately carry the same article twice
     * for a while during a changeover.
     *
     * @param list<array<string, mixed>> $duplicates gtin, products
     *
     * @return list<ConfigurationIssue>
     */
    public function fromDuplicateGtins(array $duplicates): array
    {
        $issues = [];

        foreach ($duplicates as $duplicate) {
            $issues[] = new ConfigurationIssue(
                ConfigurationFinding::DuplicateGtin,
                [
                    'gtin' => (string) ($duplicate['gtin'] ?? ''),
                    'count' => (string) ((int) ($duplicate['products'] ?? 0)),
                ],
            );
        }

        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $manufacturers title, country, email, euResponsible, physicalProducts
     * @param array<string, string>      $countryNames  ISO code => translated name
     *
     * @return list<ConfigurationIssue>
     */
    public function fromManufacturers(array $manufacturers, array $countryNames = []): array
    {
        $issues = [];

        foreach ($manufacturers as $manufacturer) {
            /*
             * A manufacturer no physical product uses is not a gap. The record
             * may have been created in advance or belong to goods that are
             * gone; nagging about it trains the operator to ignore the start
             * page, and then the real findings go unread too.
             */
            if ((int) ($manufacturer['physicalProducts'] ?? 0) < 1) {
                continue;
            }

            $title = (string) ($manufacturer['title'] ?? '');

            if ('' === trim((string) ($manufacturer['email'] ?? ''))) {
                $issues[] = new ConfigurationIssue(
                    ConfigurationFinding::ManufacturerEmailMissing,
                    ['manufacturer' => $title],
                );
            }

            /*
             * The case that gets forgotten: a Swiss or British manufacturer is
             * the normal case in an Austrian shop and needs a responsible
             * person inside the Union (GPSR Art. 16). Checking only whether a
             * manufacturer is set declares such a shop fine.
             */
            $country = (string) ($manufacturer['country'] ?? '');

            if (!EuMemberStates::contains($country) && (int) ($manufacturer['euResponsible'] ?? 0) < 1) {
                $issues[] = new ConfigurationIssue(
                    ConfigurationFinding::ManufacturerEuResponsibleMissing,
                    [
                        // The NAME where it is known, the code otherwise — the
                        // tax finding one line above says "Dänemark", and this
                        // one saying "CH" for the same kind of fact makes an
                        // operator wonder whether they are two kinds of fact.
                        'manufacturer' => $title,
                        'country' => $countryNames[$country] ?? $country,
                    ],
                );
            }
        }

        return $issues;
    }
}
