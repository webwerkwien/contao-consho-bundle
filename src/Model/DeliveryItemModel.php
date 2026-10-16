<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * How much of one order item travelled with one delivery.
 *
 * @property int    $id
 * @property int    $pid
 * @property int    $tstamp
 * @property int    $orderItem
 * @property string $quantity
 *
 * @method static DeliveryItemModel|null            findByPk($id, array $opt = [])
 * @method static Collection<DeliveryItemModel>|null findByPid($pid, array $opt = [])
 */
class DeliveryItemModel extends Model
{
    protected static $strTable = 'tl_consho_delivery_item';
}
