<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * One payment against an order. A refund is a row with a negative amount.
 *
 * @property int    $id
 * @property int    $pid
 * @property int    $tstamp
 * @property int    $receivedAt
 * @property string $amount
 * @property string $method
 * @property string $reference
 *
 * @method static PaymentModel|null            findByPk($id, array $opt = [])
 * @method static Collection<PaymentModel>|null findByPid($pid, array $opt = [])
 */
class PaymentModel extends Model
{
    protected static $strTable = 'tl_consho_payment';
}
