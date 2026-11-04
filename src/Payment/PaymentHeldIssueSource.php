<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Payments the provider has held for longer than DAYS.
 *
 * An attempt the provider reports pending (NotificationOutcome::Pending — a
 * capture under review, a transfer still clearing) is not cancelled when it
 * lapses: the cleanup cron asks again every hour, and the money is booked
 * once the provider decides. Usually within days. One still open after a
 * week is worth a look in the provider's account, so that an order does not
 * wait unnoticed for money that may never come.
 *
 * An attempt still open this long is pending by construction: the cron
 * cancels every other open attempt after PaymentStarter::ATTEMPT_LIFETIME.
 */
final class PaymentHeldIssueSource implements ConfigurationIssueSource
{
    public const DAYS = 7;

    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentAttemptRepositoryInterface $attempts,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function issues(): array
    {
        $now = null !== $this->clock ? ($this->clock)() : time();
        $held = $this->attempts->openBefore($now - self::DAYS * 86400);

        if ([] === $held) {
            return [];
        }

        $numbers = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, orderNumber FROM tl_consho_order WHERE id IN (?)', [array_map(static fn (PaymentAttempt $attempt): int => $attempt->orderId, $held)], [ArrayParameterType::INTEGER]) as $row) {
            $numbers[(int) $row['id']] = (string) $row['orderNumber'];
        }

        $orders = array_values(array_unique(array_map(static fn (PaymentAttempt $attempt): string => $numbers[$attempt->orderId] ?? '#'.$attempt->orderId, $held)));

        return [new ConfigurationIssue(ConfigurationFinding::PaymentHeld, [
            'count' => (string) \count($orders),
            'orders' => implode(', ', $orders),
        ])];
    }
}
