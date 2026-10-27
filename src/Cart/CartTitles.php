<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * The name a basket line goes by — for every article in one query.
 *
 * CartView carries no title: CartPricer prices, it does not describe. And a
 * title is needed for lines the pricer could not price at all (they arrive as
 * bare article ids in CartView::$unpriced), so the lookup cannot hang off a
 * priced line either.
 *
 * ── Always the MAIN product's title ──────────────────────────────────────
 *
 * ProductDetailAssembler fixes that rule for the storefront: "title, teaser,
 * description — always the main product's". A variant's own title column is a
 * back end label ("red / L"), written for the person maintaining the catalogue.
 * Showing it in a basket would name an article the shop never advertised under
 * that name. The join therefore walks to pid first and reads the title there.
 *
 * ── …plus the ARTICLE's own item number ──────────────────────────────────
 *
 * The title alone made two variants of one product indistinguishable — not
 * only visually: the accessible name of the quantity field and of both buttons
 * is built from it, so a screen reader read "Menge: Wanderstock" and
 * "Entfernen Wanderstock" twice over. The field ids are unique per line, but an
 * id is not a name.
 *
 * The item number is what a shop already keeps for exactly that purpose, and
 * tl_consho_product states the rule the choice rests on: sku, gtin and mpn are
 * "per ARTICLE, and never inherited from the main product". It therefore comes
 * off alias `a` — the article in the basket — while the title comes off `m`.
 * Where none is maintained (the column is optional) it is empty, and
 * CartController marks the line by its position instead.
 *
 * ── …plus the VARIANT's values ───────────────────────────────────────────
 *
 * "Wanderstock, rot, L" (spec C, E2: the values only).
 * The item number distinguishes the lines; the values describe them, and an
 * invoice line "Wanderstock" is worthless to the buyer. Values only, no axis
 * names, in the order of the detail page's axes (VariantRepository::axes():
 * by group label, then option `sorting`, `id`), one per axis like there.
 *
 * Through this class the values reach the checkout and, frozen as TEXT in
 * tl_consho_order_item.productName, the order and everything that reads it —
 * invoice, mails, back end. Text, not option ids: the ids are behind the
 * line's `product` already, and a renamed or deleted option must not change
 * what the buyer was sold.
 *
 * ── No visibility check ──────────────────────────────────────────────────
 *
 * On purpose. A product that has become invisible since it was added must
 * still be shown WITH ITS NAME, or the visitor cannot tell which line the
 * "remove" button belongs to. It has no price either way, so it cannot be
 * bought; PurchaseCheck guards every quantity change and C-5 guards the
 * checkout.
 */
final class CartTitles
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<int> $articleIds
     *
     * @return array<int, CartTitle> article id => name; an article whose row is
     *                               gone is simply absent
     */
    public function of(array $articleIds, string $language, string $fallback): array
    {
        if ([] === $articleIds) {
            return [];
        }

        // The translation hangs off the MAIN product (alias m), because that is
        // whose title is read. In the default language TranslationJoin builds
        // nothing at all.
        $translation = new TranslationJoin('tl_consho_product', $language, $fallback, 'm', 'tm');

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT a.id AS id, a.sku AS sku, %s AS title
                 FROM tl_consho_product a
                 JOIN tl_consho_product m ON m.id = COALESCE(NULLIF(a.pid, 0), a.id)
                 %s
                 WHERE a.id IN (:ids)',
                $translation->coalesce('title'),
                $translation->sql(),
            ),
            ['ids' => array_values(array_unique($articleIds)), ...$translation->params()],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $values = $this->variantValues($articleIds, $language, $fallback);
        $titles = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $title = (string) ($row['title'] ?? '');

            $titles[$id] = new CartTitle(
                implode(', ', array_filter([$title, ...($values[$id] ?? [])], static fn (string $part): bool => '' !== $part)),
                (string) ($row['sku'] ?? ''),
            );
        }

        return $titles;
    }

    /**
     * @param list<int> $articleIds
     *
     * @return array<int, list<string>> article id => its values, axis by axis
     */
    private function variantValues(array $articleIds, string $language, string $fallback): array
    {
        // Aliases tg/tn as in VariantRepository: `to` is reserved in MySQL.
        $groupLabel = new TranslationJoin('tl_consho_property_group', $language, $fallback, 'g', 'tg');
        $optionLabel = new TranslationJoin('tl_consho_property_option', $language, $fallback, 'o', 'tn');

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT po.pid AS article, g.id AS groupId, %s AS groupLabel, %s AS optionLabel
                 FROM tl_consho_product_option po
                 JOIN tl_consho_property_option o ON o.id = po.optionId
                 JOIN tl_consho_property_group g ON g.id = o.pid
                 %s
                 %s
                 WHERE po.pid IN (:ids)
                 ORDER BY po.pid, groupLabel, g.id, o.sorting, o.id',
                $groupLabel->coalesce('title'),
                $optionLabel->coalesce('title'),
                $groupLabel->sql(),
                $optionLabel->sql(),
            ),
            ['ids' => array_values(array_unique($articleIds)), ...$groupLabel->params(), ...$optionLabel->params()],
            ['ids' => ArrayParameterType::INTEGER],
        );

        /** @var array<int, array<int, string>> $byGroup */
        $byGroup = [];

        foreach ($rows as $row) {
            // Two options of one group on a variant: the first wins, as on
            // the detail page (VariantRepository::visibleVariants()).
            $byGroup[(int) $row['article']][(int) $row['groupId']] ??= trim((string) $row['optionLabel']);
        }

        return array_map(static fn (array $values): array => array_values($values), $byGroup);
    }
}
