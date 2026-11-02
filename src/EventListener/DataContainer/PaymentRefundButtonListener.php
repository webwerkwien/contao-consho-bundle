<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayoutIssueSource;

/**
 * The refund operations at a payment (block Zahlung Mollie, 4.4), each only
 * where it applies:
 *
 * - "Erstatten" at a received payment whose provider can pay back and that
 *   is not paid back in full;
 * - "Erneut auszahlen" at a refund the provider refused;
 * - "Von Hand erledigt" at a refund that failed or waits — one `sending`
 *   only once it hangs, a request takes seconds.
 */
class PaymentRefundButtonListener
{
    public function __construct(
        private readonly RefundPayout $payout,
        private readonly \Closure|null $clock = null,
    ) {
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'list.operations.refundPayment.button')]
    public function refund(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();
        $id = (int) ($record['id'] ?? 0);

        // The cheap reading first: only a provider's payment can qualify.
        if (!BigDecimal::of((string) ($record['amount'] ?? '0'))->isPositive() || null === ($record['attempt'] ?? null) || '' === (string) ($record['reference'] ?? '')
            || [] === $this->payout->refundable((int) ($record['pid'] ?? 0), $id)) {
            $operation->hide();
        }
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'list.operations.refundRetry.button')]
    public function retry(DataContainerOperation $operation): void
    {
        if ('failed' !== ($operation->getRecord()['payoutState'] ?? '')) {
            $operation->hide();
        }
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'list.operations.refundManual.button')]
    public function manual(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();
        $now = null === $this->clock ? time() : (int) ($this->clock)();

        $shown = match ((string) ($record['payoutState'] ?? '')) {
            'failed', 'requested' => true,
            'sending' => (int) ($record['tstamp'] ?? 0) < $now - RefundPayoutIssueSource::SENDING_STUCK_AFTER,
            default => false,
        };

        if (!$shown) {
            $operation->hide();
        }
    }
}
