<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\RecurringPaymentHandlerInterface;

/**
 * The payment method a follow-up order is billed with (Abo-4, P24, A5;
 * Abo-4b, P94, P99).
 *
 * Without a mandate every follow-up goes "on account" — also where the first
 * payment was prepayment. The shop's first published method of type
 * `invoice`, in the order the shop lists its methods.
 *
 * With a mandate (Abo-4b) the method that holds it, as long as the shop still
 * offers it and its module can charge (RecurringPaymentHandlerInterface).
 * Deleted, unpublished, taken off the shop, or its module gone: "on account",
 * a line in the log, and the finding says it on the start page (P99).
 *
 * The method's checkout rules (groups, same country, maximum net) do NOT
 * apply: they decide what a basket is offered, and the contract behind a
 * follow-up order was concluded long ago. Nor does its fee — the price of the
 * contract is frozen (A9), and a surcharge the customer never agreed to would
 * change it.
 */
class SubscriptionBillingMethod
{
    public function __construct(
        private readonly PaymentMethodRepositoryInterface $methods,
        // Optional for the tests built by hand; without it nothing is charged.
        private readonly PaymentHandlers|null $handlers = null,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function forShop(int $shopId, string $language = '', string $fallback = ''): PaymentMethod|null
    {
        foreach ($this->methods->forShop($shopId, $language, $fallback) as $method) {
            if (InvoicePaymentHandler::getType() === $method->type) {
                return $method;
            }
        }

        return null;
    }

    /**
     * The method that charges the subscription's mandate, or null: none, or
     * not available any more (logged, P99).
     *
     * @param array<string, mixed> $subscription the row
     */
    public function charging(array $subscription, string $language = '', string $fallback = ''): PaymentMethod|null
    {
        $methodId = (int) ($subscription['mandateMethod'] ?? 0);

        if ('' === trim((string) ($subscription['mandate'] ?? '')) || $methodId < 1 || null === $this->handlers) {
            return null;
        }

        foreach ($this->methods->forShop((int) $subscription['shop'], $language, $fallback) as $method) {
            if ($method->id === $methodId && $this->handlers->has($method->type) && $this->handlers->get($method->type) instanceof RecurringPaymentHandlerInterface) {
                return $method;
            }
        }

        $this->logger->warning(\sprintf('Subscription %s was billed "on account": the payment method %d of its mandate is not available any more.', $subscription['subscriptionNumber'] ?? '', $methodId));

        return null;
    }

    /**
     * Whether the method still charges — the finding's question (P99),
     * without the log line.
     */
    public function canCharge(int $shopId, int $methodId): bool
    {
        if (null === $this->handlers) {
            return false;
        }

        foreach ($this->methods->forShop($shopId) as $method) {
            if ($method->id === $methodId) {
                return $this->handlers->has($method->type) && $this->handlers->get($method->type) instanceof RecurringPaymentHandlerInterface;
            }
        }

        return false;
    }
}
