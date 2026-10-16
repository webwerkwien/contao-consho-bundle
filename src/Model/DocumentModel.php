<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * @property int         $id
 * @property int         $pid
 * @property int         $shop
 * @property string      $type
 * @property string      $documentNumber
 * @property int         $issuedAt
 * @property int         $relatedDocument
 * @property string|null $payload
 *
 * @method static DocumentModel|null              findByPk($id, array $opt = [])
 * @method static Collection<DocumentModel>|null  findByPid($pid, array $opt = [])
 */
class DocumentModel extends Model
{
    protected static $strTable = 'tl_consho_document';
}
