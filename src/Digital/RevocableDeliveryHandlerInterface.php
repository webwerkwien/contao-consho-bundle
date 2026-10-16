<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

/**
 * A delivery handler that wants to hear when an entitlement is revoked
 * (Nachlese E6, spec F; Michael, 2026-10-09: "wie empfohlen"). Optional, like
 * RecurringPaymentHandlerInterface: a handler without it learns of a
 * revocation only by asking `isActive()` or EntitlementQuery, as before.
 *
 * Revoked means: a withdrawal accepted for a service or a subscription line,
 * a subscription purchase withdrawn, or the operator's tick in the back end.
 * What the handler does is its own — close an account, withdraw a Composer
 * token, tell a licence server.
 *
 * Called through EntitlementRevocations at the end of the request or the
 * command, after the revocation was committed (review Nachlese, S1). A
 * handler that throws is logged and changes nothing in Consho; it should be
 * idempotent all the same.
 */
interface RevocableDeliveryHandlerInterface extends DeliveryHandlerInterface
{
    public function revoke(Entitlement $entitlement): void;
}
