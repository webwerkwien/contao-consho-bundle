<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * Product to property option, as filterable information.
 *
 * @property int $id
 * @property int $tstamp
 * @property int $pid
 * @property int $optionId
 *
 * @method static Collection<ProductPropertyModel>|null findByPid($pid, array $opt = [])
 */
class ProductPropertyModel extends Model
{
    protected static $strTable = 'tl_consho_product_property';
}
