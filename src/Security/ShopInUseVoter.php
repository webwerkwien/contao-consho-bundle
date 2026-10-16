<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

final class ShopInUseVoter extends CatalogInUseVoter
{
    protected function getTable(): string
    {
        return 'tl_consho_shop';
    }
}
