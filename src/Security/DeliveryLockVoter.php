<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

final class DeliveryLockVoter extends LockedRecordVoter
{
    protected function getTable(): string
    {
        return 'tl_consho_delivery';
    }
}
