<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * @property int    $id
 * @property int    $pid
 * @property int    $sorting
 * @property int    $tstamp
 * @property string $title
 *
 * @method static PropertyOptionModel|null                 findByPk($id, array $opt = [])
 * @method static Collection<PropertyOptionModel>|null      findByPid($pid, array $opt = [])
 */
class PropertyOptionModel extends Model
{
    protected static $strTable = 'tl_consho_property_option';
}
