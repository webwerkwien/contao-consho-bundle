<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;

/**
 * The reminders and the ends of term that are due (Abo-6): from the cron every
 * hour and from `consho:subscription:run`.
 *
 * SQL narrows, SubscriptionLifecycle decides again under the lock. A reminder
 * is mailed after its commit; one that did not leave is unmarked again and
 * tried the next hour (review Abo-6, S2) — before a renewal it is a legal
 * notice, not a courtesy. OrderEventMail answers false only when the customer's
 * mail did not go out, so a retry does not send it twice. One subscription
 * failing does not stop the others.
 */
class SubscriptionLifecycleRun
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly OrderPageLink $links,
        private readonly OrderConfirmationSourceInterface $source,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // Final and built on Contao's router, so optional for the tests; the
        // container always sets it (checked with debug:container after the
        // deploy, rule 25).
        private readonly ProductUrlGenerator|null $productUrls = null,
        // "Meine Abos" for "Lizenz verlängern" (Abo-7b, P71); optional likewise.
        private readonly ShopPageUrls|null $pageUrls = null,
    ) {
    }

    /**
     * @return array{reminders: list<ReminderDue>, changes: array<string, LifecycleChange>}
     */
    public function run(int $now): array
    {
        return ['reminders' => $this->remind($now), 'changes' => $this->transition($now)];
    }

    /**
     * @return list<ReminderDue>
     */
    public function remind(int $now): array
    {
        // A day of slack for the calendar days SubscriptionLifecycle counts.
        $candidates = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_subscription
              WHERE state IN ('active', 'suspended', 'paused') AND phase = 'minimum' AND reminderSentAt = 0
                AND (endsAt IS NULL OR endsAt = 0) AND renewedBy = 0 AND reminderDays > 0
                AND minimumEndsAt > :now AND minimumEndsAt <= :now + (reminderDays + 1) * 86400
              ORDER BY minimumEndsAt, id",
            ['now' => $now],
        ));

        $sent = [];

        foreach ($candidates as $subscriptionId) {
            try {
                $due = $this->lifecycle->remind($subscriptionId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The reminder of subscription %d could not be prepared: %s', $subscriptionId, $e->getMessage()), ['exception' => $e]);

                continue;
            }

            if (null === $due) {
                continue;
            }

            if ($this->notify($due)) {
                $sent[] = $due;

                continue;
            }

            /*
             * Review Abo-6, S2: before a renewal the reminder is the notice
             * § 6 Abs. 1 Z 2 KSchG asks for — a mail that did not leave must
             * not count as sent. Unmarked again (only this run's mark), so the
             * next hour tries anew; the log says why each time.
             */
            $this->connection->executeStatement(
                'UPDATE tl_consho_subscription SET reminderSentAt = 0 WHERE id = ? AND reminderSentAt = ?',
                [$due->subscriptionId, $now],
            );
        }

        return $sent;
    }

    /**
     * @return array<string, LifecycleChange> subscription number => what happened
     */
    public function transition(int $now): array
    {
        $candidates = $this->connection->fetchAllKeyValue(
            "SELECT id, subscriptionNumber FROM tl_consho_subscription
              WHERE (state IN ('active', 'suspended', 'paused') AND phase = 'minimum' AND minimumEndsAt > 0 AND minimumEndsAt <= :now)
                 OR (state <> 'ended' AND endsAt > 0 AND endsAt <= :now)
              ORDER BY id",
            ['now' => $now],
        );

        $changes = [];

        foreach ($candidates as $subscriptionId => $number) {
            try {
                $change = $this->lifecycle->transition((int) $subscriptionId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Subscription %s could not move on from its term: %s', $number, $e->getMessage()), ['exception' => $e]);

                continue;
            }

            if (null !== $change) {
                $changes[(string) $number] = $change;
            }
        }

        return $changes;
    }

    /**
     * @return bool whether the customer's mail left
     */
    private function notify(ReminderDue $due): bool
    {
        try {
            $subscription = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$due->subscriptionId]);
            $order = false === $subscription ? false : $this->connection->fetchAssociative(
                'SELECT * FROM tl_consho_order WHERE id = ? OR subscription = ? ORDER BY createdAt DESC, id DESC LIMIT 1',
                [(int) $subscription['firstOrder'], $due->subscriptionId],
            );
            $viewUrl = false === $order ? null : $this->links->forMail($order);

            if (false === $subscription || false === $order || null === $viewUrl) {
                $this->logger->warning(\sprintf('The reminder of subscription %s was not sent: the shop has no order page. It is tried again every hour.', $due->subscriptionNumber));

                return false;
            }

            return $this->mail->subscriptionReminder((int) $order['id'], [
                'subscription' => $due->subscriptionNumber,
                'kind' => $this->kindOf($due),
                'product' => (string) $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE id = ?', [(int) $subscription['orderItem']]),
                'termEndsAt' => $due->termEndsAt,
                'cancelUntil' => $due->cancelUntil,
                'termLength' => (int) $subscription['termLength'],
                'termUnit' => (string) $subscription['termUnit'],
                'noticeDays' => (int) $subscription['noticeDays'],
                'productUrl' => $this->productUrl($subscription, $order),
                'renewUrl' => $this->renewUrl($subscription, $order),
            ], $viewUrl);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The reminder of subscription %s was not sent: %s', $due->subscriptionNumber, $e->getMessage()), ['exception' => $e]);

            return false;
        }
    }

    private function kindOf(ReminderDue $due): string
    {
        return match ($due->renewal) {
            RenewalKind::Ends => 'ends',
            RenewalKind::SameTerm => 'sameTerm',
            // A rule from before variant A, billed otherwise: it ends.
            RenewalKind::Indefinite => $due->endsForLackOfPrice ? 'endsNoMonthly' : 'indefinite',
        };
    }

    /**
     * "Meine Abos" of the start point (Abo-7b, P71), where "Lizenz verlängern"
     * is — null where the shop names no such page; the reminder links the
     * product page then.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $order
     */
    private function renewUrl(array $subscription, array $order): string|null
    {
        if (null === $this->pageUrls) {
            return null;
        }

        try {
            $rootId = $this->source->rootOf((int) $subscription['shop'], (string) $order['language']);
            $pageId = null === $rootId ? 0 : (int) $this->connection->fetchOne('SELECT consho_subscriptionsPage FROM tl_page WHERE id = ?', [$rootId]);

            return $pageId > 0 ? $this->pageUrls->ofPageId($pageId) : null;
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf('The reminder of subscription %s goes without a link to "Meine Abos": %s', (string) $subscription['subscriptionNumber'], $e->getMessage()));

            return null;
        }
    }

    /**
     * The product page, to buy again (A3) — null where none can be built; the
     * reminder goes without the button then.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $order
     */
    private function productUrl(array $subscription, array $order): string|null
    {
        if (null === $this->productUrls) {
            return null;
        }

        try {
            $rootId = $this->source->rootOf((int) $subscription['shop'], (string) $order['language']);

            if (null === $rootId) {
                return null;
            }

            $articleId = (int) $subscription['product'];
            $parent = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$articleId]);

            return $this->productUrls->generate($parent > 0 ? $parent : $articleId, $rootId, variantId: $parent > 0 ? $articleId : 0);
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf('The reminder of subscription %s goes without a product link: %s', (string) $subscription['subscriptionNumber'], $e->getMessage()));

            return null;
        }
    }
}
