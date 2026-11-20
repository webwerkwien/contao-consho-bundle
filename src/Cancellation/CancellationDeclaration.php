<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

/**
 * What the customer declared on the confirmation page, before it is recorded
 * (§ 312k Abs. 2 Nr. 1 BGB; P61).
 *
 * `requestedAt` null: the earliest possible date (§ 312k Abs. 5). `reason`
 * only for an extraordinary cancellation. The e-mail address is not in here:
 * the confirmation goes to the subscription's (P58).
 *
 * From the operator (Abo-9, P128): always ordinary, `requestedAt` is the end
 * itself — one of OperatorCancellationDates —, `reason` is kept and told to
 * the customer, `name` is the back end user. `renewalHint`: the plan is
 * still on offer, so the mail points to "Lizenz verlängern".
 */
final readonly class CancellationDeclaration
{
    public function __construct(
        public int $subscriptionId,
        public CancellationSource $source,
        public CancellationKind $kind,
        public string $name,
        public int|null $requestedAt = null,
        public string $reason = '',
        public bool $renewalHint = false,
    ) {
    }
}
