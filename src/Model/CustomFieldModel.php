<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int    $id
 * @property int    $tstamp
 * @property string $title
 * @property string $alias
 * @property string $type
 *
 * @method static CustomFieldModel|null findByPk($id, array $opt = [])
 */
class CustomFieldModel extends Model
{
    protected static $strTable = 'tl_consho_custom_field';
}
