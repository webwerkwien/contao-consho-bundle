<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int      $id
 * @property int      $pid
 * @property string   $purpose
 * @property string   $tokenHash
 * @property int      $createdAt
 * @property int|null $expiresAt
 * @property int|null $usedAt
 *
 * @method static OrderTokenModel|null findOneBy($col, $val, array $opt = [])
 */
class OrderTokenModel extends Model
{
    protected static $strTable = 'tl_consho_order_token';
}
