<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * One payment method by its id, whatever its state (C-11).
 *
 * Kept apart from PaymentMethodRepositoryInterface, which answers "what may a
 * buyer choose" and therefore skips unpublished methods. A notification asks
 * something else: which secret checks this message. The method may have been
 * unpublished since the attempt started, and money that arrives for it must
 * still be recorded.
 */
interface PaymentMethodLookupInterface
{
    public function byId(int $methodId): PaymentMethod|null;
}
