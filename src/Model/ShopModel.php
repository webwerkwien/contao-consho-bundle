<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int         $id
 * @property int         $tstamp
 * @property string      $title
 * @property string      $currency
 * @property string|null $currencies
 * @property string      $country
 * @property string|null $deliveryCountries
 * @property string|null $shippingMethods
 * @property string|null $paymentMethods
 * @property string      $afterAddToCart
 * @property int         $mailTransport
 * @property string      $operatorName
 * @property string      $operatorAddress
 * @property string      $fallbackLanguage
 * @property string      $vatId
 * @property bool        $taxAtDestination
 * @property string|null $netPriceGroups
 * @property string|null $ageVerifiedGroups
 * @property bool        $guestCheckout
 * @property bool        $termsRequired
 * @property string      $buyButtonLabel
 * @property string      $invoiceCompany
 * @property string      $invoiceLegalForm
 * @property string      $invoiceStreet
 * @property string      $invoicePostal
 * @property string      $invoiceCity
 * @property string      $invoiceCountry
 * @property string|null $invoiceLogo      binary UUID — invoice, mails and {{consho_logo}}
 *
 * @method static ShopModel|null findByPk($id, array $opt = [])
 * @method static ShopModel|null findOneBy($col, $val, array $opt = [])
 */
class ShopModel extends Model
{
    protected static $strTable = 'tl_consho_shop';
}
