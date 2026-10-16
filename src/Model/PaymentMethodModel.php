<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * Module settings are virtual fields in `jsonData`; read them through
 * DbalPaymentMethodRepository, which decodes them into the config array.
 *
 * @property int         $id
 * @property int         $tstamp
 * @property string      $type
 * @property string      $title
 * @property string|null $description
 * @property string|null $instructions
 * @property bool        $published
 * @property string|null $jsonData
 *
 * @method static PaymentMethodModel|null findByPk($id, array $opt = [])
 */
class PaymentMethodModel extends Model
{
    protected static $strTable = 'tl_consho_payment_method';
}
