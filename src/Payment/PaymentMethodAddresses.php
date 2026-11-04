<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;

/**
 * Where a payment method can be reached from outside (spec Zahlung PayPal,
 * 4.4): the domains of the start points whose shop offers it, and the
 * webhook address a provider with one fixed address is given.
 *
 * Not the host of the current request: a module registers its webhook while
 * the operator saves in the back end, which may run under another domain
 * than the shop — and the address has to keep working after the operator
 * has logged out. A method is chosen per shop (C2), so it may run under
 * several domains; the route does not depend on the host, and the first
 * domain in the order of the start points is enough. A module that stored
 * the address it registered compares its host with domains() to notice a
 * domain that went away.
 */
final class PaymentMethodAddresses
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @return list<string> lower case, in the order of the start points, without empties and duplicates
     */
    public function domains(int $methodId): array
    {
        $shops = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, paymentMethods FROM tl_consho_shop') as $shop) {
            if (\in_array($methodId, MethodSettings::selectedIds($shop['paymentMethods'] ?? null), true)) {
                $shops[] = (int) $shop['id'];
            }
        }

        if ([] === $shops) {
            return [];
        }

        $domains = [];

        foreach ($this->connection->fetchAllAssociative("SELECT dns, consho_shop FROM tl_page WHERE type = 'root' AND consho_shop > 0 ORDER BY sorting, id") as $root) {
            $domain = strtolower(trim((string) $root['dns']));

            if ('' !== $domain && \in_array((int) $root['consho_shop'], $shops, true) && !\in_array($domain, $domains, true)) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }

    /**
     * https://<first domain>/_consho/payment/webhook/<id>, or '' when no
     * start point offering the method has a domain.
     */
    public function webhookUrl(int $methodId): string
    {
        $domain = $this->domains($methodId)[0] ?? '';

        if ('' === $domain) {
            return '';
        }

        return 'https://'.$domain.$this->router->generate('consho_payment_webhook', ['method' => $methodId], UrlGeneratorInterface::ABSOLUTE_PATH);
    }
}
