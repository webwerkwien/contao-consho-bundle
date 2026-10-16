<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * Visibility of a product in a shop.
 *
 * @property int $id
 * @property int $tstamp
 * @property int $pid
 * @property int $shop
 *
 * @method static Collection<ProductShopModel>|null findByPid($pid, array $opt = [])
 */
class ProductShopModel extends Model
{
    protected static $strTable = 'tl_consho_product_shop';
}
