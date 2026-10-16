<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Symfony\Component\HttpFoundation\Response;

/**
 * A delivery handler that shows what it delivered on the order page (block
 * licence, LZ-1; Michael, 2026-10-12). Optional, like
 * RevocableDeliveryHandlerInterface: a handler without it shows nothing there.
 *
 * The core keeps the rules (E1–E4): only on the order page, never in a mail;
 * only while the entitlement is usable (EntitlementQuery); a download and an
 * action only for an item deliveredItems() names with them. A handler is asked
 * only for entitlements of its own type, so it need not check that again.
 */
interface PresentableDeliveryHandlerInterface extends DeliveryHandlerInterface
{
    /**
     * @return list<DeliveredItem>
     */
    public function deliveredItems(Entitlement $entitlement): array;

    /**
     * The file behind an item's download button, or null when there is none
     * (the page answers 404). The core sets the private, no-store headers.
     */
    public function download(Entitlement $entitlement, string $item): Response|null;

    /**
     * Runs an item's action. Returns the notice the page shows afterwards — a
     * key of `CONSHO.orderPage.notice` the module brings in its language file
     * —, or null when nothing was done (the page answers 404).
     */
    public function act(Entitlement $entitlement, string $item, string $action): string|null;
}
