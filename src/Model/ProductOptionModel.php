<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * Product to property option, as variant axis.
 *
 * @property int $id
 * @property int $tstamp
 * @property int $pid
 * @property int $optionId
 *
 * @method static Collection<ProductOptionModel>|null findByPid($pid, array $opt = [])
 */
class ProductOptionModel extends Model
{
    protected static $strTable = 'tl_consho_product_option';
}
