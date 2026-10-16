<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * One tax rate, valid for a country over a period.
 *
 * `rate` is a string, not a float: it comes out of a decimal(6,4) column and
 * stays a string all the way into the calculation, which uses brick/math.
 *
 * @property int      $id
 * @property int      $pid
 * @property int      $tstamp
 * @property string   $country
 * @property string   $rate
 * @property int|null $validFrom
 * @property int|null $validTo
 *
 * @method static TaxRateModel|null            findByPk($id, array $opt = [])
 * @method static Collection<TaxRateModel>|null findBy($col, $val, array $opt = [])
 */
class TaxRateModel extends Model
{
    protected static $strTable = 'tl_consho_tax_rate';
}
