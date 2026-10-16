<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * One order, with everything it needs frozen onto it.
 *
 * `member` may be 0 — that is a guest order, not an incomplete one. The name,
 * email and both addresses are copies, so the record stays true even after the
 * customer changes theirs.
 *
 * @property int         $id
 * @property int         $tstamp
 * @property int         $createdAt
 * @property int         $shop
 * @property string      $orderNumber
 * @property int         $orderStatus
 * @property string      $language
 * @property int         $member
 * @property string      $currency
 * @property string      $netTotal
 * @property string      $taxTotal
 * @property string      $grandTotal
 * @property int         $shippingMethod
 * @property string      $shippingMethodTitle
 * @property int         $paymentMethod
 * @property string      $paymentMethodTitle
 * @property string|null $paymentInstructions
 * @property int|null    $emailConfirmedAt
 * @property string      $vatId
 * @property string      $vatIdStatus
 * @property string      $vatIdRequestIdentifier
 * @property string      $salutation
 * @property string      $firstname
 * @property string      $lastname
 * @property string      $email
 * @property string      $phone
 * @property string      $company
 * @property string      $billingStreet
 * @property string      $billingPostal
 * @property string      $billingCity
 * @property string      $billingState
 * @property string      $billingCountry
 * @property string      $shippingCompany
 * @property string      $shippingFirstname
 * @property string      $shippingLastname
 * @property string      $shippingStreet
 * @property string      $shippingPostal
 * @property string      $shippingCity
 * @property string      $shippingState
 * @property string      $shippingCountry
 *
 * @method static OrderModel|null            findByPk($id, array $opt = [])
 * @method static OrderModel|null            findOneBy($col, $val, array $opt = [])
 * @method static Collection<OrderModel>|null findBy($col, $val, array $opt = [])
 */
class OrderModel extends Model
{
    protected static $strTable = 'tl_consho_order';
}
