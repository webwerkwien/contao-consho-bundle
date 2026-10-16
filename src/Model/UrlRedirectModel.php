<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;

/**
 * @property int         $id
 * @property int         $pid
 * @property string|null $oldPath
 * @property int         $productId
 * @property int         $createdAt
 *
 * @method static UrlRedirectModel|null findOneBy($col, $val, array $opt = [])
 */
class UrlRedirectModel extends Model
{
    protected static $strTable = 'tl_consho_url_redirect';
}
