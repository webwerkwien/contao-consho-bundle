<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int         $id
 * @property int         $tstamp
 * @property string      $title
 * @property string|null $logo
 * @property string      $street
 * @property string      $postal
 * @property string      $city
 * @property string      $state
 * @property string      $country
 * @property string      $email
 * @property string      $contactUrl
 * @property string      $website
 * @property int         $euResponsible
 *
 * @method static ManufacturerModel|null findByPk($id, array $opt = [])
 */
class ManufacturerModel extends Model
{
    protected static $strTable = 'tl_consho_manufacturer';
}
