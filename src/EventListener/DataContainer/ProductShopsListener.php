<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductShops;

/**
 * Assigns a product to shops through tl_consho_product_shop.
 *
 * The catalogue is shared and visibility is per shop, so the assignment lives
 * in its own table with a UNIQUE index on (pid, shop) rather than in a column
 * on the product. The back end still wants one plain multi-select, which is
 * what this listener provides.
 *
 * `shops` therefore has no `sql` key — there is no such column. The save
 * callback writes the rows itself and returns null, and the field is marked
 * `doNotSaveEmpty`, which is what keeps DC_Table from adding it to the UPDATE:
 * every field it collects in `arrSubmit` goes into that statement by name, and
 * a name without a column is an SQL error.
 */
class ProductShopsListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ProductShops $shops,
    ) {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.shops.options')]
    public function options(DataContainer|null $dc = null): array
    {
        /** @var array<int, string> $shops */
        $shops = $this->connection->fetchAllKeyValue(
            'SELECT id, title FROM tl_consho_shop ORDER BY title',
        );

        $mainProductId = null !== $dc ? $this->mainProductIdOf((int) $dc->id) : 0;

        if ($mainProductId < 1) {
            return $shops;
        }

        /*
         * A variant may narrow its main product's shops but never widen them —
         * see the specification, section 3.7. Offering only what the main
         * product carries makes the invalid choice unreachable instead of
         * silently dropping it on save.
         */
        return array_intersect_key($shops, array_flip($this->shops->of($mainProductId)));
    }

    /**
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.shops.load')]
    public function load(mixed $value, DataContainer $dc): array
    {
        return array_map('strval', $this->shops->of((int) $dc->id));
    }

    /**
     * Unchecked (ProductShops::assign()): the options above offer only valid
     * shops.
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.shops.save')]
    public function save(mixed $value, DataContainer $dc): null
    {
        $this->shops->assign((int) $dc->id, array_map(intval(...), array_filter(StringUtil::deserialize($value, true), is_scalar(...))));

        // Returning null keeps the value out of arrSubmit — see the class note.
        return null;
    }

    /**
     * The id of this product's main product, or 0 if it is one itself.
     */
    private function mainProductIdOf(int $productId): int
    {
        if ($productId < 1) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT pid FROM tl_consho_product WHERE id = ?',
            [$productId],
        );
    }

}
