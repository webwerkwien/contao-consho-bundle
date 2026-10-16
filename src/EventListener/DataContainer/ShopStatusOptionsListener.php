<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderStatusRepository;

/**
 * The statuses a shop may switch its orders to automatically (D-1) — its own.
 */
#[AsCallback(table: 'tl_consho_shop', target: 'fields.statusAfterPayment.options')]
#[AsCallback(table: 'tl_consho_shop', target: 'fields.statusAfterDelivery.options')]
#[AsCallback(table: 'tl_consho_shop', target: 'fields.statusWhenSettled.options')]
class ShopStatusOptionsListener
{
    public function __construct(private readonly DbalOrderStatusRepository $statuses)
    {
    }

    /**
     * @return array<int, string>
     */
    public function __invoke(DataContainer|null $dc = null): array
    {
        $shopId = null === $dc ? 0 : (int) $dc->id;

        return $shopId > 0 ? $this->statuses->titlesOfShop($shopId) : [];
    }
}
