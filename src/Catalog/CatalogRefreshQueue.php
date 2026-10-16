<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Symfony\Contracts\Service\ResetInterface;

/**
 * What the catalogue projections need once the current request is done: the
 * products whose list prices and visibility have to be recalculated, and
 * whether shops and root pages changed in a way the visibility projection has
 * to be compared against (B-10).
 *
 * The triggers only report, they do not calculate: ondelete runs while the row
 * is still in the database, and copying a product calls oncopy once per copied
 * price. Collected here, each product is calculated once, after every write of
 * the request (CatalogRefreshListener).
 *
 * Replaces ListPriceRefreshQueue: both projections follow the same changes, in
 * a fixed order, so they share one queue.
 */
final class CatalogRefreshQueue implements ResetInterface
{
    /**
     * @var array<int, true>
     */
    private array $productIds = [];

    private bool $shopSync = false;

    public function add(int $productId): void
    {
        if ($productId > 0) {
            $this->productIds[$productId] = true;
        }
    }

    /**
     * A shop or a root page changed. Only a request: whether anything has to
     * be rebuilt is decided by comparing fingerprints (VisibilityProjector::
     * syncShops()), so saving a page title costs a comparison, not a rebuild.
     */
    public function requestShopSync(): void
    {
        $this->shopSync = true;
    }

    /**
     * The collected product ids — and they are gone afterwards.
     *
     * @return list<int>
     */
    public function take(): array
    {
        $productIds = array_keys($this->productIds);
        $this->productIds = [];

        return $productIds;
    }

    /**
     * Whether a shop sync was requested — and the request is gone afterwards.
     */
    public function takeShopSync(): bool
    {
        $requested = $this->shopSync;
        $this->shopSync = false;

        return $requested;
    }

    public function reset(): void
    {
        $this->productIds = [];
        $this->shopSync = false;
    }
}
