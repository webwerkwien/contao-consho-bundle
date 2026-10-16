<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Model;

use Contao\Model;
use Contao\Model\Collection;

/**
 * @property int    $id
 * @property int    $pid
 * @property string $title
 * @property string $type
 * @property string $host
 * @property int    $port
 * @property string $encryption
 * @property string $username
 * @property string $password
 * @property string $tenantId
 * @property string $clientId
 * @property string $clientSecret
 * @property string $senderAddress
 * @property string $senderName
 *
 * @method static MailTransportModel|null             findByPk($id, array $opt = [])
 * @method static Collection<MailTransportModel>|null findByPid($pid, array $opt = [])
 */
class MailTransportModel extends Model
{
    protected static $strTable = 'tl_consho_mail_transport';
}
