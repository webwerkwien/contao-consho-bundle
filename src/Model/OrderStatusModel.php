<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * A status value belonging to one shop.
 *
 * The title is the operator's word for it; the four flags are what the system
 * reads. Nothing anywhere may branch on the title.
 *
 * @property int    $id
 * @property int    $pid
 * @property int    $sorting
 * @property int    $tstamp
 * @property string $title
 * @property bool   $isPaid
 * @property bool   $isCancelled
 * @property bool   $isShipped
 * @property bool   $locksChanges
 *
 * @method static OrderStatusModel|null            findByPk($id, array $opt = [])
 * @method static Collection<OrderStatusModel>|null findByPid($pid, array $opt = [])
 */
class OrderStatusModel extends Model
{
    protected static $strTable = 'tl_consho_order_status';
}
