<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Order\OverpaidOrders;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayoutIssueSource;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\UnassignedRefunds;

/**
 * "Geldflüsse" (block Geldflüsse, 3.6) in every row, red while something
 * waits for the operator:
 *
 *  - a refund made at the provider, not recorded or assigned yet
 *  - a chargeback that stands (a reversed one has nothing left to do)
 *  - a refund the provider refused, or one whose request hangs (the
 *    thresholds of RefundPayoutIssueSource)
 *  - money paid too much (OverpaidOrders)
 *  - a refund that names no payment (UnassignedRefunds)
 *
 * Read once per list, not per row.
 */
class MoneyFlowsButtonListener
{
    public const ICON_OPEN = 'bundles/contaoconsho/icons/money-flows--open.svg';

    /** @var array<int, true>|null the orders where something waits */
    private array|null $waiting = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly OverpaidOrders|null $overpaid = null,
        private readonly UnassignedRefunds|null $unassigned = null,
        private readonly \Closure|null $clock = null,
    ) {
    }

    #[AsCallback(table: 'tl_consho_order', target: 'list.operations.moneyFlows.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $this->waiting ??= $this->read();

        if (isset($this->waiting[(int) ($operation->getRecord()['id'] ?? 0)])) {
            $operation['icon'] = self::ICON_OPEN;
        }
    }

    /**
     * @return array<int, true>
     */
    private function read(): array
    {
        $now = null === $this->clock ? time() : (int) ($this->clock)();
        $ids = [
            ...$this->connection->fetchFirstColumn(
                "SELECT DISTINCT pid FROM tl_consho_provider_event
                  WHERE state = 'open' AND (kind = 'refund' OR reversedAt = 0)",
            ),
            // The same thresholds as the start page's finding: a request the
            // hourly cron will still send, or a refund pending at the provider
            // for a few days, waits for nobody yet.
            ...$this->connection->fetchFirstColumn(
                "SELECT DISTINCT pid FROM tl_consho_payment
                  WHERE payoutState = 'failed'
                     OR (payoutState = 'sending' AND tstamp < ?)
                     OR (payoutState = 'requested' AND payoutReference <> '' AND receivedAt < ?)
                     OR (payoutState = 'requested' AND payoutReference = '' AND tstamp < ?)",
                [$now - RefundPayoutIssueSource::SENDING_STUCK_AFTER, $now - RefundPayoutIssueSource::PENDING_STUCK_AFTER, $now - RefundPayoutIssueSource::UNSENT_STUCK_AFTER],
            ),
            ...array_keys($this->overpaid?->all() ?? []),
            ...array_column($this->unassigned?->all() ?? [], 'order'),
        ];

        return array_fill_keys(array_map(intval(...), $ids), true);
    }
}
