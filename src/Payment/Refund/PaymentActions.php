<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

use Brick\Math\BigDecimal;
use Webwerkwien\ContaoConshoBundle\Security\LockedRecords;

/**
 * Which money operations a payment row offers, decided in one place for the
 * payment list (PaymentRefundButtonListener) and the page "Geldflüsse"
 * (block Geldflüsse, 3.6):
 *
 * - "Erstatten" at a received payment not paid back in full — through its
 *   provider where it can, otherwise by hand;
 * - "Erneut auszahlen" at a refund the provider refused;
 * - "Von Hand erledigt" at a refund that failed or waits — one `sending`
 *   only once it hangs, a request takes seconds;
 * - "Korrigieren" at a locked payment booked too high (Geldflüsse E8);
 * - "Bearbeiten" at a row not locked yet.
 *
 * Each page behind an operation checks again; this only decides where it is
 * offered.
 */
final class PaymentActions
{
    /** The operations in the order the payment list draws them. */
    public const ALL = ['refundPayment', 'refundRetry', 'refundManual', 'correctPayment', 'edit'];

    /**
     * @param LockedRecords|null $locks also a payment with a refund against it counts as locked; without, `lockedAt` alone
     */
    public function __construct(
        private readonly RefundPayout $payout,
        private readonly \Closure|null $clock = null,
        private readonly LockedRecords|null $locks = null,
    ) {
    }

    /**
     * @param array<string, mixed> $record a row of tl_consho_payment
     *
     * @return list<string> the operations offered, out of ALL
     */
    public function of(array $record): array
    {
        return array_values(array_filter(self::ALL, fn (string $operation): bool => match ($operation) {
            'refundPayment' => $this->canRefund($record),
            'refundRetry' => $this->canRetry($record),
            'refundManual' => $this->canMarkManual($record),
            'correctPayment' => $this->canCorrect($record),
            default => $this->canEdit($record),
        }));
    }

    /**
     * @param array<string, mixed> $record
     */
    public function canRefund(array $record): bool
    {
        // The cheap reading first: only a payment of its own can qualify —
        // any, not only a provider's (Geldflüsse T4).
        return BigDecimal::of((string) ($record['amount'] ?? '0'))->isPositive()
            && 0 === (int) ($record['refundOf'] ?? 0)
            && null !== $this->payout->remainder((int) ($record['pid'] ?? 0), (int) ($record['id'] ?? 0));
    }

    /**
     * A locked payment with something left; an unlocked one is simply edited.
     *
     * @param array<string, mixed> $record
     */
    public function canCorrect(array $record): bool
    {
        // Locked by lockedAt or by a refund against it — correctable() asks both.
        return BigDecimal::of((string) ($record['amount'] ?? '0'))->isPositive()
            && null !== $this->payout->correctable((int) ($record['id'] ?? 0));
    }

    /**
     * @param array<string, mixed> $record
     */
    public function canRetry(array $record): bool
    {
        return 'failed' === ($record['payoutState'] ?? '');
    }

    /**
     * @param array<string, mixed> $record
     */
    public function canMarkManual(array $record): bool
    {
        $now = null === $this->clock ? time() : (int) ($this->clock)();

        return match ((string) ($record['payoutState'] ?? '')) {
            'failed', 'requested' => true,
            'sending' => (int) ($record['tstamp'] ?? 0) < $now - RefundPayoutIssueSource::SENDING_STUCK_AFTER,
            default => false,
        };
    }

    /**
     * A locked row opens read-only (LockedRecordListener): not offered as
     * editing.
     *
     * @param array<string, mixed> $record
     */
    public function canEdit(array $record): bool
    {
        return null === $this->locks ? null === ($record['lockedAt'] ?? null) : !$this->locks->isPaymentLocked($record);
    }
}
