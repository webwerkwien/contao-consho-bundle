<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int    $id
 * @property int    $tstamp
 * @property string $title
 * @property bool   $filterable
 *
 * @method static PropertyGroupModel|null findByPk($id, array $opt = [])
 */
class PropertyGroupModel extends Model
{
    protected static $strTable = 'tl_consho_property_group';
}
