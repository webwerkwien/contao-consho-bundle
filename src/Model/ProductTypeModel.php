<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int    $id
 * @property int    $tstamp
 * @property string $title
 * @property string $kind
 *
 * @method static ProductTypeModel|null findByPk($id, array $opt = [])
 */
class ProductTypeModel extends Model
{
    protected static $strTable = 'tl_consho_product_type';
}
