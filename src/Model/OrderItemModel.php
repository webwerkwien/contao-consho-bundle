<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * One line of an order, with every figure frozen as a value.
 *
 * `product` is the only reference and it is allowed to dangle: a deleted
 * product must not invalidate a past order.
 *
 * @property int    $id
 * @property int    $pid
 * @property int    $sorting
 * @property int    $tstamp
 * @property int    $product
 * @property string $productName
 * @property string $sku
 * @property string $quantity
 * @property string $unitPrice
 * @property string $taxRate
 * @property string $taxAmount
 * @property string $lineTotal
 * @property string $priceSource
 *
 * @method static OrderItemModel|null            findByPk($id, array $opt = [])
 * @method static Collection<OrderItemModel>|null findByPid($pid, array $opt = [])
 */
class OrderItemModel extends Model
{
    protected static $strTable = 'tl_consho_order_item';
}
