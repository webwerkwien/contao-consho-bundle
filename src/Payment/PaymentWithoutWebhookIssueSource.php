<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Payments that arrived only because the cleanup cron asked the provider
 * before the attempt lapsed (spec Zahlung PayPal, PP2 d). One is chance — a
 * webhook delayed past half an hour; any is worth a look, because until the
 * cron asked the order had no invoice, status or mail. Seven days, so the
 * finding ends by itself once the address is right.
 */
final class PaymentWithoutWebhookIssueSource implements ConfigurationIssueSource
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
        $counts = $this->attempts->polledSince($now - self::DAYS * 86400);

        if ([] === $counts) {
            return [];
        }

        $titles = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM tl_consho_payment_method') as $row) {
            $titles[(int) $row['id']] = (string) $row['title'];
        }

        $issues = [];

        foreach ($counts as $methodId => $count) {
            $issues[] = new ConfigurationIssue(ConfigurationFinding::PaymentWithoutWebhook, [
                'method' => $titles[$methodId] ?? '#'.$methodId,
                'count' => (string) $count,
            ]);
        }

        return $issues;
    }
}
