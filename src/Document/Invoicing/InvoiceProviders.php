<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * The installed invoicing modules by name (R15) — the same shape as
 * DeliveryHandlers and PaymentHandlers.
 */
class InvoiceProviders
{
    /**
     * @param ServiceProviderInterface<InvoiceProviderInterface> $providers
     */
    public function __construct(
        #[AutowireLocator('consho.invoice_provider', defaultIndexMethod: 'getName')]
        private readonly ServiceProviderInterface $providers,
    ) {
    }

    /**
     * The provider of a name stored at a shop, null where none is installed
     * under it — "consho", "off" and a removed module alike.
     */
    public function get(string $name): InvoiceProviderInterface|null
    {
        return '' !== $name && $this->providers->has($name) ? $this->providers->get($name) : null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->providers->getProvidedServices()));
    }
}
