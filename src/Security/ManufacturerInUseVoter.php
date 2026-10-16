<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

final class ManufacturerInUseVoter extends CatalogInUseVoter
{
    protected function getTable(): string
    {
        return 'tl_consho_manufacturer';
    }
}
