<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * A shop assignment ProductShops::set() will not write; the message says why.
 */
final class ProductShopsRefused extends \DomainException
{
}
