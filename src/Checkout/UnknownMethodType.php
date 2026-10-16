<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * A method record names a type no installed service provides — typically a
 * module that has been removed. Thrown rather than skipped silently: the
 * caller decides whether the method is merely unavailable.
 */
final class UnknownMethodType extends \RuntimeException
{
    public static function named(string $type): self
    {
        return new self(\sprintf('No service provides the method type "%s".', $type));
    }
}
