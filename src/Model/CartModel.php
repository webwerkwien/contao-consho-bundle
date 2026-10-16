<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int         $id
 * @property int         $tstamp
 * @property string|null $tokenHash
 * @property int         $member
 * @property int         $shop
 * @property string|null $checkoutData
 * @property int         $order
 * @property string      $notice
 * @property string      $state
 * @property int         $createdAt
 * @property int         $updatedAt
 *
 * @method static CartModel|null findByPk($id, array $opt = [])
 */
class CartModel extends Model
{
    protected static $strTable = 'tl_consho_cart';
}
