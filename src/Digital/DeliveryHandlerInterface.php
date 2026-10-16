<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How an entitlement is redeemed (F-4, F8): a file, a key from a pool, or
 * whatever a module brings — a Composer token, an account on a learning
 * platform.
 *
 * The core brings the file and the key pool (F-5, F-6); anything that needs a
 * service of its own is a module registering under its own key, the same way
 * payment handlers and shipping types do. The key is stored on the product
 * (`digitalDelivery`), so it must never change once used.
 *
 * Deliberately small. The core decides WHEN something is released (payment,
 * consent, withdrawal period — DigitalRelease) and FOR HOW LONG (validUntil);
 * a handler only decides WHAT the customer gets and whether it can hand it
 * out right now.
 */
#[AutoconfigureTag('consho.delivery_handler')]
interface DeliveryHandlerInterface
{
    public static function getType(): string;

    /**
     * Called once, inside the release's transaction, right after the
     * entitlement row was written.
     *
     * The entitlement may already REST (Abo-5): released while its
     * subscription waits for an overdue payment. Reserve what it needs, but
     * whatever grants use from outside — an account, a licence server — asks
     * `$entitlement->isActive()` or EntitlementQuery first, and again later.
     *
     * @return bool false when it cannot deliver yet — an empty key pool, say.
     *              The line is then not released and the next run tries again;
     *              everything this call wrote is rolled back with the row.
     */
    public function release(Entitlement $entitlement): bool;
}
