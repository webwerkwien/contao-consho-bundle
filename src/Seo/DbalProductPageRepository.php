<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationJoin;

/**
 * The two lookups every product address needs.
 *
 * Both are read-only and both are asked once per product address, so they are
 * memoised for the request — the sitemap in B-6 generates one address per
 * product and would otherwise ask for the same page a thousand times.
 */
final class DbalProductPageRepository implements ProductPageRepositoryInterface
{
    /** @var array<int, int|null> */
    private array $pages = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findForRootPage(int $rootPageId): ?int
    {
        if (\array_key_exists($rootPageId, $this->pages)) {
            return $this->pages[$rootPageId];
        }

        $candidates = $this->connection->fetchFirstColumn(
            'SELECT id FROM tl_page WHERE type = :type AND published = 1 ORDER BY id',
            ['type' => ProductPageController::TYPE],
        );

        foreach ($candidates as $candidate) {
            if ($rootPageId === $this->rootOf((int) $candidate)) {
                return $this->pages[$rootPageId] = (int) $candidate;
            }
        }

        return $this->pages[$rootPageId] = null;
    }

    /**
     * @see ProductAddressLookup::find() — the inverse. The four expressions
     *      below must stay character-identical to the ones built there: what
     *      this method writes into an address, that one has to find again.
     */
    public function urlValues(int $productId, string $language = '', string $fallback = ''): array
    {
        $product = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 'tp');
        $manufacturer = new TranslationJoin('tl_consho_manufacturer', $language, $fallback, 'm', 'tm');
        $type = new TranslationJoin('tl_consho_product_type', $language, $fallback, 't', 'tt');

        $joins = implode(' ', array_filter([$product->sql(), $manufacturer->sql(), $type->sql()]));

        /*
         * The manufacturer and the product type contribute their alias, not
         * their title: a corrected name must not move an address. Both may be
         * absent — the template then drops that segment (ProductPathTemplate).
         *
         * sku is language-neutral (specification 3.2) — it never goes through
         * coalesce().
         */
        $row = $this->connection->fetchAssociative(
            \sprintf(
                'SELECT
                    %s AS alias,
                    p.sku AS sku,
                    %s AS manufacturer,
                    %s AS type
                FROM tl_consho_product p
                LEFT JOIN tl_consho_manufacturer m ON m.id = p.manufacturer
                LEFT JOIN tl_consho_product_type t ON t.id = p.productType
                %s
                WHERE p.id = :id',
                $product->coalesce('alias'),
                \sprintf("COALESCE(%s, '')", $manufacturer->coalesce('alias')),
                \sprintf("COALESCE(%s, '')", $type->coalesce('alias')),
                $joins,
            ),
            [
                'id' => $productId,
                ...$product->params(),
                ...$manufacturer->params(),
                ...$type->params(),
            ],
        );

        if (false === $row) {
            return [];
        }

        return array_map(static fn (mixed $value): string => (string) $value, $row);
    }

    public function rootPageOf(int $pageId): ?int
    {
        return $this->rootOf($pageId);
    }

    /**
     * @see ProductAddressLookup::find() — same note as urlValues(): the four
     *      expressions must stay character-identical to the ones built there.
     */
    public function urlValuesForShop(int $shopId, string $language = '', string $fallback = ''): array
    {
        return $this->urlValuesInShop($shopId, $language, $fallback);
    }

    public function urlValuesForProductInShop(int $productId, int $shopId, string $language = '', string $fallback = ''): array
    {
        return $this->urlValuesInShop($shopId, $language, $fallback, 'p.id', $productId);
    }

    public function urlValuesForManufacturerInShop(int $manufacturerId, int $shopId, string $language = '', string $fallback = ''): array
    {
        return $this->urlValuesInShop($shopId, $language, $fallback, 'p.manufacturer', $manufacturerId);
    }

    public function urlValuesForProductTypeInShop(int $productTypeId, int $shopId, string $language = '', string $fallback = ''): array
    {
        return $this->urlValuesInShop($shopId, $language, $fallback, 'p.productType', $productTypeId);
    }

    /**
     * One query for all of them: the whole shop, or the part of it one
     * product, manufacturer or product type accounts for.
     *
     * Not copies: the join and the main-product rule have to be identical.
     * A narrowed list answering where the shop-wide list would not — a variant,
     * a product of another shop — would file a redirect that record() never
     * would.
     *
     * $column is one of three literals from the methods above, never input.
     *
     * @param 'p.id'|'p.manufacturer'|'p.productType'|null $column
     *
     * @return array<int, array<string, string>>
     */
    private function urlValuesInShop(int $shopId, string $language, string $fallback, ?string $column = null, int $value = 0): array
    {
        $product = new TranslationJoin('tl_consho_product', $language, $fallback, 'p', 'tp');
        $manufacturer = new TranslationJoin('tl_consho_manufacturer', $language, $fallback, 'm', 'tm');
        $type = new TranslationJoin('tl_consho_product_type', $language, $fallback, 't', 'tt');

        $joins = implode(' ', array_filter([$product->sql(), $manufacturer->sql(), $type->sql()]));

        $rows = $this->connection->fetchAllAssociative(
            \sprintf(
                'SELECT
                    p.id AS id,
                    %s AS alias,
                    p.sku AS sku,
                    %s AS manufacturer,
                    %s AS type
                FROM tl_consho_product p
                JOIN tl_consho_product_shop ps ON ps.pid = p.id AND ps.shop = :shop
                LEFT JOIN tl_consho_manufacturer m ON m.id = p.manufacturer
                LEFT JOIN tl_consho_product_type t ON t.id = p.productType
                %s
                WHERE p.pid = 0%s
                ORDER BY p.id',
                $product->coalesce('alias'),
                \sprintf("COALESCE(%s, '')", $manufacturer->coalesce('alias')),
                \sprintf("COALESCE(%s, '')", $type->coalesce('alias')),
                $joins,
                null === $column ? '' : ' AND '.$column.' = :narrow',
            ),
            [
                'shop' => $shopId,
                ...(null === $column ? [] : ['narrow' => $value]),
                ...$product->params(),
                ...$manufacturer->params(),
                ...$type->params(),
            ],
        );

        $values = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            unset($row['id']);

            $values[$id] = array_map(static fn (mixed $value): string => (string) $value, $row);
        }

        return $values;
    }

    /**
     * Walks up the tree to the root page.
     *
     * A loop of single-row lookups rather than a recursive query: a page tree is
     * a handful of levels deep, the rows are hit by primary key, and the result
     * is memoised per root page above. A recursive CTE would be cleverer and
     * harder to read for no measurable gain.
     *
     * The depth guard is not for deep trees, it is for a broken one — a page
     * whose pid points at itself would otherwise spin forever.
     */
    private function rootOf(int $pageId): ?int
    {
        for ($depth = 0; $depth < 32; ++$depth) {
            $row = $this->connection->fetchAssociative(
                'SELECT id, pid, type FROM tl_page WHERE id = :id',
                ['id' => $pageId],
            );

            if (false === $row) {
                return null;
            }

            if ('root' === $row['type']) {
                return (int) $row['id'];
            }

            $pageId = (int) $row['pid'];

            if (0 === $pageId) {
                return null;
            }
        }

        return null;
    }
}
