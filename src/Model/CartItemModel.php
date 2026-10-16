<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int    $id
 * @property int    $pid
 * @property int    $tstamp
 * @property int    $product
 * @property string $quantity
 * @property int    $addedAt
 *
 * @method static CartItemModel|null findByPk($id, array $opt = [])
 */
class CartItemModel extends Model
{
    protected static $strTable = 'tl_consho_cart_item';
}
