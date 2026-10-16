<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A GARAN label that does not reach the customer (block GARAN, GA7).
 *
 * - **Duration without label**: the shop names no guarantee, though it is
 *   owed "unter Verwendung der harmonisierten Kennzeichnung" before the
 *   purchase (§ 4 Abs. 1 Z 12a, § 8 Abs. 1 FAGG).
 * - **Label without a valid duration**: 0, 1 or 2 years — Consho does not
 *   show a label for anything but "mehr als zwei Jahre" (review GARAN, 7:
 *   a label with 2 years fell through both checks before).
 * - **Label not public** (acceptance on c5): the shop leaves it out rather
 *   than print a broken image.
 * - **Label too large** (review GARAN, 5): it travels in every confirmation.
 * - **A value at a variant** (review GARAN, 2): DurabilityGuarantees reads the
 *   main product only, so it does nothing — a main product hung below
 *   another keeps its values.
 * - **A nested label that never shows** (GA8): no full label with a valid
 *   duration for it to open, or a folder the web cannot reach. A note.
 *
 * Whether the image matches duration, manufacturer and model cannot be
 * checked — that stays with the operator (GA7).
 *
 * Published goods only, the kind by the rule of DbalArticleShippingData —
 * a value on anything else is ProductKindMismatchIssueSource's. Warnings,
 * except the size: the information is owed before the purchase, and nothing
 * in the shop says it is missing.
 */
final class DurabilityGuaranteeIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        // Optional for the tests that do not look at the file.
        private readonly PublicFiles|null $files = null,
    ) {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.pid, a.title, a.durabilityYears, a.durabilityLabel, a.durabilityNested
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product m ON m.id = a.pid AND a.pid > 0
               LEFT JOIN tl_consho_product_type tm ON tm.id = m.productType AND m.productType > 0
               LEFT JOIN tl_consho_product_type ta ON ta.id = a.productType AND a.productType > 0
              WHERE a.published = 1 AND COALESCE(tm.kind, ta.kind, 'physical') = 'physical'
                AND (a.durabilityYears > 0 OR a.durabilityLabel IS NOT NULL OR a.durabilityNested IS NOT NULL)
              ORDER BY a.title, a.id",
        );

        $issues = [];

        foreach ($rows as $row) {
            $years = GuaranteeYears::of($row['durabilityYears']);
            $qualifies = GuaranteeYears::qualifies($years);
            $label = \is_string($row['durabilityLabel']) && 16 === \strlen($row['durabilityLabel']) ? $row['durabilityLabel'] : null;
            $context = ['product' => (string) $row['title'], 'years' => GuaranteeYears::display($years, (string) ($GLOBALS['TL_LANG']['MSC']['decimalSeparator'] ?? '.'))];

            if ((int) $row['pid'] > 0) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DurabilityOnVariant, $context);

                continue;
            }

            // GA8: shown only with a full label to open, from a public folder.
            // Named only where nothing about the full label says it already
            // (review GA8, 4): without label and duration — no other finding
            // speaks then — or beside a full label that shows, from a folder
            // that is not public. A missing label, a missing duration and a
            // label not public each have their own finding, which covers it.
            $nested = \is_string($row['durabilityNested']) && 16 === \strlen($row['durabilityNested']) ? $row['durabilityNested'] : null;
            $labelShows = null !== $label && $qualifies && (null === $this->files || $this->files->isPublic($label));

            if (null !== $nested && ((null === $label && !$qualifies) || ($labelShows && null !== $this->files && !$this->files->isPublic($nested)))) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DurabilityNestedUnused, $context);
            }

            if (null === $label) {
                // 1 or 2 years without a label is no case of Z 12a at all.
                if ($qualifies) {
                    $issues[] = new ConfigurationIssue(ConfigurationFinding::DurabilityLabelMissing, $context);
                }

                continue;
            }

            if (!$qualifies) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DurabilityYearsMissing, $context);
            }

            if (null === $this->files) {
                continue;
            }

            if ($qualifies && !$this->files->isPublic($label)) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DurabilityLabelNotPublic, $context);
            }

            $size = $this->files->size($label);

            if (null !== $size && $size > PublicFiles::MAIL_IMAGE_MAX_BYTES) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::DurabilityLabelTooLarge, [...$context, 'size' => (string) (int) ceil($size / 1024)]);
            }
        }

        return $issues;
    }
}
