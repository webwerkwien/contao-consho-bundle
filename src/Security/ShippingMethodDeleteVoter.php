<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

/**
 * A shipping method an order names cannot be deleted — why in
 * CatalogReferences, since CW-5 shared with the delete command.
 */
final class ShippingMethodDeleteVoter extends CatalogInUseVoter
{
    protected function getTable(): string
    {
        return 'tl_consho_shipping_method';
    }
}
