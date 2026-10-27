<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;

/**
 * One run over the due subscriptions (Abo-4, A14, P28): for the cron every
 * hour and for `consho:subscription:bill`.
 *
 * Each subscription is billed until it is no longer due — periods missed
 * while the server was down come as one order each, at most MAX_PERIODS per
 * run, so a broken row cannot fill the order list. Every period is its own
 * transaction (SubscriptionBiller); its mail goes out after that commit, and
 * a mail that fails leaves the order standing (OrderEventMail never throws).
 *
 * One subscription failing does not stop the others: logged, and the next
 * run tries it again — its period has not moved.
 *
 * A period billed with the subscription's mandate is charged right after its
 * commit (Abo-4b, P94) instead of being announced: its mail follows from
 * what the charge comes to (P95, P97).
 */
class SubscriptionBillingRun
{
    public const MAX_PERIODS = 12;

    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionBiller $biller,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // Optional for the tests built by hand; the container wires it.
        private readonly SubscriptionCharger|null $charger = null,
    ) {
    }

    /**
     * @return list<BilledPeriod>
     */
    public function run(int $now): array
    {
        // The same question SubscriptionBiller::isDue() asks again under the lock.
        $due = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_subscription
              WHERE state IN ('active', 'suspended') AND nextBillingAt > 0 AND nextBillingAt <= ?
                AND (phase = 'indefinite' OR nextBillingAt < minimumEndsAt)
                AND (endsAt IS NULL OR endsAt = 0 OR nextBillingAt < endsAt)
              ORDER BY nextBillingAt, id",
            [$now],
        ));

        $billed = [];

        foreach ($due as $subscriptionId) {
            try {
                for ($i = 0; $i < self::MAX_PERIODS; ++$i) {
                    $period = $this->biller->billDue($subscriptionId, $now);

                    if (null === $period) {
                        break;
                    }

                    $billed[] = $period;

                    if ($period->charged && null !== $this->charger) {
                        $this->charger->charge($period->orderId, $now);
                    } else {
                        $this->notify($period);
                    }
                }
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Subscription %d could not be billed: %s', $subscriptionId, $e->getMessage()), ['exception' => $e]);
            }
        }

        return $billed;
    }

    private function notify(BilledPeriod $period): void
    {
        // The order stands whatever happens to its mail: a
        // failing link must neither read as "not billed" nor stop the
        // subscription's further periods.
        try {
            $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$period->orderId]);
            $viewUrl = false === $order ? null : $this->links->forMail($order);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The invoice mail of follow-up order %d was not sent: %s', $period->orderId, $e->getMessage()), ['exception' => $e]);

            return;
        }

        if (null === $viewUrl) {
            $this->logger->warning(\sprintf('The invoice mail of follow-up order %d was not sent: the shop has no order page.', $period->orderId));

            return;
        }

        $this->mail->subscriptionInvoice($period->orderId, $period->subscriptionNumber, $period->periodFrom, $period->periodUntil, $viewUrl);
    }
}
