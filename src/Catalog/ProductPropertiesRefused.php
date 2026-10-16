<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * Property values ProductProperties will not write; the message says why.
 *
 * A \RuntimeException: thrown from the mask's save callback it is shown at
 * the field, as before.
 */
final class ProductPropertiesRefused extends \RuntimeException
{
}
