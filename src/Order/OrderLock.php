<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * Decides when an order stops being mutable.
 *
 * Freezing serves exactly one purpose: a document must stay reproducible.
 * Before a document exists and before money has moved there is nothing to
 * protect — quantities may still change, and the price is then resolved
 * afresh, because otherwise a scale price would no longer match the quantity
 * it is charged for. What the customer originally ordered is kept by Contao's
 * tl_version, so nothing is lost by allowing the change.
 *
 * Three triggers, deliberately no more:
 *
 *   - a document exists — the strongest, because it has left the building
 *   - money has moved — refunding is a business process, not an edit
 *   - the status says so — the operator's own workflow decision
 */
final class OrderLock
{
    public function isLocked(bool $hasPayment, bool $statusLocks, int $documentCount): bool
    {
        return $hasPayment || $statusLocks || $documentCount > 0;
    }

    /**
     * @throws OrderIsLocked
     */
    public function assertMutable(bool $hasPayment, bool $statusLocks, int $documentCount): void
    {
        // Checked strongest first: with several reasons at once the document is
        // the one worth reporting, because it is the one that cannot be undone
        // by deleting a row.
        if ($documentCount > 0) {
            throw OrderIsLocked::becauseOfDocument();
        }

        if ($hasPayment) {
            throw OrderIsLocked::becauseOfPayment();
        }

        if ($statusLocks) {
            throw OrderIsLocked::becauseOfStatus();
        }
    }
}
