<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Webwerkwien\ContaoConshoBundle\Checkout\UnknownMethodType;

/**
 * The installed shipping types, keyed by getType().
 *
 * A locator rather than an iterator: a checkout asks for the two or three types
 * a shop selected, and a carrier module's constructor should not run for a
 * cart it never serves.
 */
class ShippingMethodTypes
{
    /**
     * @param ServiceProviderInterface<ShippingMethodTypeInterface> $types
     */
    public function __construct(
        #[AutowireLocator('consho.shipping_method_type', defaultIndexMethod: 'getType')]
        private readonly ServiceProviderInterface $types,
    ) {
    }

    public function has(string $type): bool
    {
        return $this->types->has($type);
    }

    public function get(string $type): ShippingMethodTypeInterface
    {
        if (!$this->types->has($type)) {
            throw UnknownMethodType::named($type);
        }

        return $this->types->get($type);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->types->getProvidedServices()));
    }
}
