<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;

/**
 * "Zahlungsziel verschieben" (spec Abo, Stundung; E8).
 *
 * The operator agreed a later date with the customer. Until now the only way
 * to lift the rest of a subscription was a payment, and recording one that
 * had not come in would stand wrongly in the books. This moves the order's
 * `paymentDueAt` instead — what the overdue note, the subscription's rest
 * and the dashboard all read.
 *
 * For every unpaid, not cancelled order with a term, not only a
 * subscription's follow-up. Only LATER: an
 * earlier date would make an order overdue after the fact. The invoice keeps
 * the term it was issued with — it is a document; the agreement is the
 * operator's with the customer, so no mail goes out. The system log keeps
 * who moved what. The subscriptions of the order are assessed at once, as
 * after a payment: one that rested runs again and says so ("wieder aktiv").
 */
class PaymentDeferral
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly SubscriptionDelinquencyRun|null $subscriptions = null,
        #[Autowire(service: 'monolog.logger.contao.general')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Whether the order's term can be moved at all.
     *
     * @param array<string, mixed> $order a row of tl_consho_order
     */
    public function isDeferrable(array $order): bool
    {
        if ((int) ($order['paymentDueAt'] ?? 0) < 1 || null !== ($order['cancelledAt'] ?? null)) {
            return false;
        }

        $balance = $this->balances->of((int) $order['id']);

        return null !== $balance && !$balance->cancelled && BigDecimal::of($balance->outstanding())->isPositive();
    }

    /**
     * @param string $day the new last day of the term, Y-m-d
     *
     * @return string|null null when moved; otherwise the reason it was not: `notDeferrable`, `invalidDate`, `notLater`
     */
    public function defer(int $orderId, string $day, int $now, string $by = ''): string|null
    {
        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);

        if (false === $order || !$this->isDeferrable($order)) {
            return 'notDeferrable';
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, new \DateTimeZone(date_default_timezone_get()));

        if (false === $date || $date->format('Y-m-d') !== $day) {
            return 'invalidDate';
        }

        // The last second of the day, like PaymentTerm: payable BY that day.
        $dueAt = $date->setTime(23, 59, 59)->getTimestamp();
        $old = (int) $order['paymentDueAt'];

        if ($dueAt <= $old) {
            return 'notLater';
        }

        $this->connection->update('tl_consho_order', ['paymentDueAt' => $dueAt, 'tstamp' => $now], ['id' => $orderId]);

        $this->logger->info(\sprintf(
            'Payment term of order %s moved from %s to %s%s',
            (string) $order['orderNumber'],
            date('Y-m-d', $old),
            $day,
            '' !== $by ? ' by '.$by : '',
        ));

        $this->subscriptions?->afterPayment($orderId, $now);

        return null;
    }
}
