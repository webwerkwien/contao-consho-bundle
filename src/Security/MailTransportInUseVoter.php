<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

final class MailTransportInUseVoter extends CatalogInUseVoter
{
    protected function getTable(): string
    {
        return 'tl_consho_mail_transport';
    }
}
