<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

final class PaymentLockVoter extends LockedRecordVoter
{
    protected function getTable(): string
    {
        return 'tl_consho_payment';
    }
}
