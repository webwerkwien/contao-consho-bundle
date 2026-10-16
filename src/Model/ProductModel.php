<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * Products and their variants.
 *
 * The decimal fields are typed as string, not float, on purpose. Contao
 * returns DECIMAL columns as strings, and that is what keeps the four decimal
 * places the schema exists for — a cast to float would throw away exactly the
 * precision that shipping brackets and price sums depend on. Arithmetic runs
 * through brick/math, never through PHP's native float type.
 *
 * @property int         $id
 * @property int         $pid
 * @property int         $sorting
 * @property int         $tstamp
 * @property string      $title
 * @property string      $alias
 * @property string      $sku
 * @property string      $gtin
 * @property string      $mpn
 * @property int         $minimumAge
 * @property int         $productType
 * @property int         $taxClass
 * @property int         $manufacturer
 * @property string|null $safetyWarnings
 * @property string|null $safetyInformation
 * @property string|null $teaser
 * @property string|null $metaDescription
 * @property string|null $description
 * @property string|null $images
 * @property string      $weight
 * @property string      $shippingWeight
 * @property string      $length
 * @property string      $width
 * @property string      $height
 * @property string      $unit
 * @property string      $purchaseUnit
 * @property string      $referenceUnit
 * @property string      $minPurchase
 * @property string      $purchaseSteps
 * @property string|null $customFieldValues
 * @property bool        $published
 * @property string      $start
 * @property string      $stop
 *
 * @method static ProductModel|null                 findByPk($id, array $opt = [])
 * @method static Collection<ProductModel>|null     findByPid($pid, array $opt = [])
 */
class ProductModel extends Model
{
    protected static $strTable = 'tl_consho_product';
}
