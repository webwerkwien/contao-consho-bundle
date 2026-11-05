<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\CoreBundle\Framework\Adapter;
use Contao\Message;
use Contao\StringUtil;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReport;

/**
 * What the back end says after "Als versandt melden" — the button in the
 * delivery list and the tick in the delivery mask say the same.
 */
final class DeliveryReportMessages
{
    /**
     * @param Adapter<Message> $message
     */
    public static function add(Adapter $message, DeliveryReport $result, string $orderNumber): void
    {
        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery']['reportResult'] ?? [];
        $number = StringUtil::specialchars($orderNumber);

        match ($result->outcome) {
            'reported' => $result->mailSent
                ? $message->addConfirmation(\sprintf($lang['reported'] ?? '%s', $number))
                : $message->addInfo(\sprintf($lang['reportedNoMail'] ?? '%s', $number)),
            'tooMuch' => $message->addError(\sprintf($lang['tooMuch'] ?? '%s', StringUtil::specialchars(implode(', ', $result->tooMuch)))),
            'empty' => $message->addError($lang['empty'] ?? 'empty'),
            'alreadyReported' => $message->addInfo($lang['alreadyReported'] ?? 'alreadyReported'),
            default => $message->addError($lang['unknown'] ?? 'unknown'),
        };
    }
}
