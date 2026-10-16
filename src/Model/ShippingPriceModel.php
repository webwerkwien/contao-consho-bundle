<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * One amount of a shipping method in one currency. Only net counts; `net` is
 * a string out of a decimal column, straight into brick/math.
 *
 * @property int    $id
 * @property int    $pid
 * @property int    $tstamp
 * @property string $currency
 * @property string $net
 * @property string $inputCountry
 * @property string $inputMode
 *
 * @method static ShippingPriceModel|null findByPk($id, array $opt = [])
 */
class ShippingPriceModel extends Model
{
    protected static $strTable = 'tl_consho_shipping_price';
}
