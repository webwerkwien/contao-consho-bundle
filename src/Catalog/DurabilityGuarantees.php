<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The manufacturer's durability guarantee of an article (block GARAN) — the
 * one place for product page, checkout and the snapshot at purchase.
 *
 * - From the MAIN product: a variant inherits (GA2).
 * - Physical goods only (GA5), by the rule of DbalArticleShippingData: the
 *   main product's type, else the article's own, no type at all is physical
 *   (review GARAN, 1 — a variant with a digital type of its own below a main
 *   product without one is not goods).
 * - Both halves or nothing: a duration without its label cannot be shown in
 *   the harmonised form, and a label without a duration is one Consho does
 *   not know to be valid. Each half alone is a finding
 *   (DurabilityGuaranteeIssueSource), never half a display.
 */
class DurabilityGuarantees
{
    /**
     * Where the label's QR code leads (DVO 2025/1960, Anhang II). "A clickable
     * link to the same destination as the QR code should always be available"
     * (Commission guidelines, April 2026, 3.3.2) — GA10.
     */
    public const INFO_URL = 'https://europa.eu/youreurope/commercial-guarantee-durability/index.htm';

    public function __construct(
        private readonly Connection $connection,
        /*
         * A label the web cannot reach is no label (acceptance on c5): the
         * page would show a broken image, and the confirmation would carry
         * a label nobody saw before the button. Left out everywhere at once,
         * and DurabilityGuaranteeIssueSource says why. Optional for the tests.
         */
        private readonly PublicFiles|null $files = null,
    ) {
    }

    /**
     * @param list<int> $articleIds articles, variants or main products
     *
     * The nested label (GA8) rides along: only with a full label to open,
     * only from a public folder, else null.
     *
     * @return array<int, array{years: string, label: string, nested: string|null}> by article id; years canonical (GuaranteeYears), labels as UUID strings
     */
    public function of(array $articleIds): array
    {
        $articleIds = array_values(array_unique(array_filter($articleIds, static fn (int $id): bool => $id > 0)));

        if ([] === $articleIds) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.id, m.durabilityYears, m.durabilityLabel, m.durabilityNested
               FROM tl_consho_product a
               JOIN tl_consho_product m ON m.id = CASE WHEN a.pid > 0 THEN a.pid ELSE a.id END
               LEFT JOIN tl_consho_product_type tm ON tm.id = m.productType AND m.productType > 0
               LEFT JOIN tl_consho_product_type ta ON ta.id = a.productType AND a.productType > 0
              WHERE a.id IN (?) AND m.durabilityYears > 2 AND m.durabilityLabel IS NOT NULL
                AND COALESCE(tm.kind, ta.kind, 'physical') = 'physical'",
            [$articleIds],
            [ArrayParameterType::INTEGER],
        );

        $guarantees = [];
        $public = [];

        foreach ($rows as $row) {
            $label = (string) $row['durabilityLabel'];
            $years = GuaranteeYears::of($row['durabilityYears']);

            if (!GuaranteeYears::qualifies($years) || 16 !== \strlen($label) ||!($public[$label] ??= null === $this->files || $this->files->isPublic($label))) {
                continue;
            }

            $nested = \is_string($row['durabilityNested'] ?? null) && 16 === \strlen($row['durabilityNested']) ? $row['durabilityNested'] : null;

            if (null !== $nested && !($public[$nested] ??= null === $this->files || $this->files->isPublic($nested))) {
                $nested = null;
            }

            $guarantees[(int) $row['id']] = ['years' => $years, 'label' => StringUtil::binToUuid($label), 'nested' => null === $nested ? null : StringUtil::binToUuid($nested)];
        }

        return $guarantees;
    }
}
