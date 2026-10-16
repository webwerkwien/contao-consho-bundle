<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * Turns a product into a variant of another by setting its `pid`.
 *
 * The minimum needed to reach variants at all — A-2 created the column and the
 * VariantResolver, but no field, so a variant could not be created in the back
 * end. The comfortable variant generator (pick an axis, get every combination)
 * belongs to block B; this is the plain parent selector underneath it.
 */
class ProductParentListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.pid.options')]
    public function options(DataContainer|null $dc = null): array
    {
        /*
         * Only main products are offered. A variant of a variant is the one
         * shape the model rules out — see VariantResolver, which relies on a
         * variant having no variants of its own to save a query.
         */
        /** @var array<int, string> $products */
        $products = $this->connection->fetchAllKeyValue(
            'SELECT id, title FROM tl_consho_product WHERE pid = 0 AND id != ? ORDER BY title',
            [null !== $dc ? (int) $dc->id : 0],
        );

        return $products;
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.pid.save')]
    public function save(mixed $value, DataContainer $dc): int
    {
        $parentId = (int) $value;

        if ($parentId < 1) {
            return 0;
        }

        $productId = (int) $dc->id;

        if ($parentId === $productId) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['CONSHO']['errors']['variantSelf']);
        }

        // The guard the options list cannot give: this record may already have
        // variants hanging off it, and making it a variant would orphan them.
        $ownVariants = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM tl_consho_product WHERE pid = ?',
            [$productId],
        );

        if ($ownVariants > 0) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['variantHasVariants'], $ownVariants),
            );
        }

        return $parentId;
    }
}
