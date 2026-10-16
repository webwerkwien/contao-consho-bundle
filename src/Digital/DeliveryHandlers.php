<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The registered delivery handlers by key (F-4, F8) — the same shape as
 * PaymentHandlers and ShippingMethodTypes.
 */
class DeliveryHandlers
{
    /**
     * @param ServiceProviderInterface<DeliveryHandlerInterface> $handlers
     */
    public function __construct(
        #[AutowireLocator('consho.delivery_handler', defaultIndexMethod: 'getType')]
        private readonly ServiceProviderInterface $handlers,
    ) {
    }

    public function has(string $type): bool
    {
        return '' !== $type && $this->handlers->has($type);
    }

    public function get(string $type): DeliveryHandlerInterface
    {
        return $this->handlers->get($type);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->handlers->getProvidedServices()));
    }
}
