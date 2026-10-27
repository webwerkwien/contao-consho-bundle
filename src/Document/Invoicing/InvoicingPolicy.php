<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Doctrine\DBAL\Connection;

/**
 * Who issues a shop's invoices, numbers them and sends them (R15, R18, R19).
 *
 * | tl_consho_shop.invoicing           | issuer  | Consho numbers | Consho sends     |
 * |------------------------------------|---------|----------------|------------------|
 * | consho                             | consho  | yes            | yes              |
 * | module that passes Consho's on     | consho  | yes            | !sendsItself()   |
 * | module that issues itself          | module  | no             | !sendsItself()   |
 * | module that is not installed       | module  | no             | no — it waits    |
 * | off                                | off     | no             | no               |
 *
 * A module that passes Consho's invoices on needs Consho's number ranges and
 * invoice sender as much as Consho alone does: the numbers are Consho's.
 *
 * Read for every document, not cached: a shop switched between two documents
 * of one request is rare, and a stale answer would number a document wrongly
 * for good.
 */
class InvoicingPolicy
{
    public function __construct(
        private readonly Connection $connection,
        private readonly InvoiceProviders $providers,
    ) {
    }

    public function forShop(int $shopId): Invoicing
    {
        $stored = $this->connection->fetchOne('SELECT invoicing FROM tl_consho_shop WHERE id = ?', [$shopId]);
        $name = \is_string($stored) && '' !== $stored ? $stored : Invoicing::CONSHO;

        return $this->forName($name, $shopId);
    }

    /**
     * The same for a name already known — a cancellation follows the issuer
     * recorded at its invoice, not the shop's setting today (R20).
     */
    public function forName(string $name, int $shopId): Invoicing
    {
        if (Invoicing::CONSHO === $name) {
            return new Invoicing(Invoicing::CONSHO, null, true, true);
        }

        if (Invoicing::OFF === $name) {
            return new Invoicing(Invoicing::OFF, null, false, false);
        }

        $provider = $this->providers->get($name);

        if (null === $provider) {
            return new Invoicing($name, null, false, false);
        }

        $sends = !$provider->sendsItself($shopId);

        return $provider->issuesItself($shopId)
            ? new Invoicing($name, $provider, false, $sends)
            : new Invoicing(Invoicing::CONSHO, $provider, true, $sends);
    }
}
