<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int         $id
 * @property int         $pid
 * @property string      $ptable
 * @property string      $language
 * @property int         $tstamp
 * @property string      $title
 * @property string|null $alias
 * @property string|null $teaser
 * @property string|null $metaDescription
 * @property string|null $description
 * @property string|null $safetyWarnings
 * @property string|null $safetyInformation
 */
class TranslationModel extends Model
{
    protected static $strTable = 'tl_consho_translation';
}
