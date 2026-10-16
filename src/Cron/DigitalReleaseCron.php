<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;

/**
 * Releases the digital lines that were waiting (F-4): for the end of the
 * withdrawal period (F16), or for a key pool to be refilled (F19).
 *
 * Hourly, because a customer who waited fourteen days should not wait a
 * fifteenth for a daily job. The candidates are orders with a digital line
 * that no reported delivery covers yet; DigitalRelease decides the rest and
 * does nothing for an order that is not due. One order failing does not stop
 * the others.
 *
 * What an accepted withdrawal took back is never released, so it no longer
 * keeps an order a candidate (spec F, review K1). An unpaid prepayment stays
 * one until the operator cancels (block G decided so for stock), and an empty
 * key pool on purpose: the hourly look is what releases once it is refilled.
 */
#[AsCronJob('hourly')]
class DigitalReleaseCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly DigitalRelease $releases,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = null !== $this->clock ? ($this->clock)() : time();

        $orders = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT i.pid
               FROM tl_consho_order_item i
               INNER JOIN tl_consho_order o ON o.id = i.pid
              WHERE i.lineType = 'product' AND i.kind <> 'physical'
                AND o.cancelledAt IS NULL AND o.anonymizedAt IS NULL
                AND NOT EXISTS (SELECT 1 FROM tl_consho_withdrawal w WHERE w.pid = o.id AND w.state = 'received')
                AND i.quantity > COALESCE((
                    SELECT SUM(di.quantity)
                      FROM tl_consho_delivery_item di
                      INNER JOIN tl_consho_delivery d ON d.id = di.pid
                     WHERE di.orderItem = i.id AND d.reportedAt IS NOT NULL
                ), 0) + COALESCE((
                    SELECT SUM(wi.acceptedQuantity)
                      FROM tl_consho_withdrawal_item wi
                      INNER JOIN tl_consho_withdrawal w ON w.id = wi.pid
                     WHERE wi.orderItem = i.id AND w.state = 'accepted'
                ), 0)",
        );

        foreach ($orders as $orderId) {
            try {
                $this->releases->releaseDue((int) $orderId, $now, true);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Digital lines of order %d could not be released: %s', (int) $orderId, $e->getMessage()));
            }
        }
    }
}
