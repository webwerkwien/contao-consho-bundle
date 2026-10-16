<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * One price for a product, customer group, currency and quantity threshold.
 *
 * `net` and `minQuantity` are strings, not floats: they come out of
 * decimal columns and stay strings all the way into brick/math.
 *
 * @property int      $id
 * @property int      $pid
 * @property int      $tstamp
 * @property string   $currency
 * @property string   $net
 * @property string   $inputCountry
 * @property string   $inputMode
 * @property int      $customerGroup
 * @property string   $minQuantity
 * @property int|null $validFrom
 * @property int|null $validTo
 *
 * @method static PriceModel|null            findByPk($id, array $opt = [])
 * @method static Collection<PriceModel>|null findByPid($pid, array $opt = [])
 */
class PriceModel extends Model
{
    protected static $strTable = 'tl_consho_price';
}
