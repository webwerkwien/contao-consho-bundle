<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * One shipment against an order. What travelled is in its items.
 *
 * @property int    $id
 * @property int    $pid
 * @property int    $tstamp
 * @property int    $shippedAt
 * @property string $carrier
 * @property string $trackingNumber
 *
 * @method static DeliveryModel|null            findByPk($id, array $opt = [])
 * @method static Collection<DeliveryModel>|null findByPid($pid, array $opt = [])
 */
class DeliveryModel extends Model
{
    protected static $strTable = 'tl_consho_delivery';
}
