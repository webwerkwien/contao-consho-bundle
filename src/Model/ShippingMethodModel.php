<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int         $id
 * @property int         $tstamp
 * @property string      $type
 * @property string      $title
 * @property string|null $description
 * @property string      $taxMode
 * @property int         $taxClass
 * @property bool        $published
 *
 * @method static ShippingMethodModel|null findByPk($id, array $opt = [])
 */
class ShippingMethodModel extends Model
{
    protected static $strTable = 'tl_consho_shipping_method';
}
