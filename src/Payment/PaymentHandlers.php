<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;
use Webwerkwien\ContaoConshoBundle\Checkout\UnknownMethodType;

/**
 * The installed payment handlers, keyed by getType(). A locator for the same
 * reason as ShippingMethodTypes.
 *
 * ── The test provider exists only with CONSHO_TEST_PAYMENT=1 (C-11) ──────
 *
 * It confirms payments without taking money, so on a real shop it must not
 * answer anywhere: not in the type list of the mask, not in the checkout, not
 * at the notification route. Filtered HERE rather than left out of the
 * container, because this is the one place every one of those asks — and
 * because the variable is read when a request needs it, not when the cache
 * is built: switching it off takes effect without cache:clear.
 *
 * Exactly "1", not any truthy spelling: the spec names that value, and a
 * switch that also took "yes" or "on" would be one more thing to explain.
 */
class PaymentHandlers
{
    /**
     * @param ServiceProviderInterface<PaymentHandlerInterface> $handlers
     * @param string|null                                       $testPayment nullable for the same reason as NoticeParamIssueSource's allow list:
     *                                                                       `default::` resolves an unset variable to NULL
     */
    public function __construct(
        #[AutowireLocator('consho.payment_handler', defaultIndexMethod: 'getType')]
        private readonly ServiceProviderInterface $handlers,
        #[Autowire('%env(default::CONSHO_TEST_PAYMENT)%')]
        private readonly string|null $testPayment = null,
    ) {
    }

    public function has(string $type): bool
    {
        return $this->handlers->has($type) && $this->offers($type);
    }

    public function get(string $type): PaymentHandlerInterface
    {
        if (!$this->has($type)) {
            throw UnknownMethodType::named($type);
        }

        return $this->handlers->get($type);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_values(array_filter(
            array_map(strval(...), array_keys($this->handlers->getProvidedServices())),
            $this->offers(...),
        ));
    }

    public function testPaymentEnabled(): bool
    {
        return '1' === trim((string) $this->testPayment);
    }

    private function offers(string $type): bool
    {
        return TestPaymentHandler::getType() !== $type || $this->testPaymentEnabled();
    }
}
