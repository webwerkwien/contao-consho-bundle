<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;

/**
 * "Kündigen" in the subscription list (Abo-9, P128): greyed out where there
 * is nothing left to cancel — the subscription has ended or already has its
 * end.
 */
class SubscriptionCancelButtonListener
{
    #[AsCallback(table: 'tl_consho_subscription', target: 'list.operations.cancel.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();

        if ('ended' === (string) ($record['state'] ?? '') || (int) ($record['endsAt'] ?? 0) > 0) {
            $operation->disable();
        }
    }
}
