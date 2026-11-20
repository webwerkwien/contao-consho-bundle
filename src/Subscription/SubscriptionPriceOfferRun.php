<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Customer\MySubscriptionsFlow;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The price offers that are due (Abo-10): from the cron every hour and from
 * `consho:subscription:run`, before the reminders — an offer is part of what
 * the reminder may name (P119).
 *
 * SQL narrows to the subscriptions that run on; SubscriptionPriceOffers
 * decides under the lock. The mail goes after the commit; one that did not
 * leave takes the offer back (P135), the next hour tries anew. One
 * subscription failing does not stop the others.
 *
 * Beside new offers: the void (P124), the lapse at the deadline (P122) and
 * the reminder seven days before it (P118).
 */
class SubscriptionPriceOfferRun
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionPriceOffers $offers,
        private readonly OrderPageLink $links,
        private readonly OrderConfirmationSourceInterface $source,
        private readonly OrderEventMail $mail,
        private readonly MoneyFormatter $money,
        // Confirms a declined offer's cancellation (P121).
        private readonly CancellationRecorder $recorder,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // "Meine Abos", where the answer is given; optional for the tests,
        // the container always sets it. Without it the mail has no button.
        private readonly ShopPageUrls|null $pageUrls = null,
    ) {
    }

    /**
     * Void first, so an offer whose subscription ends is not lapsed or
     * reminded of; the lapse before the reminder, so a run that comes late
     * does not remind of a deadline already gone.
     *
     * @return array{voided: list<string>, lapsed: list<PriceOfferDue>, reminders: list<PriceOfferDue>, offers: list<PriceOfferDue>}
     */
    public function run(int $now): array
    {
        return [
            'voided' => $this->void($now),
            'lapsed' => $this->lapse($now),
            'reminders' => $this->remind($now),
            'offers' => $this->offer($now),
        ];
    }

    /**
     * P124.
     *
     * @return list<string> the subscription numbers whose offer became void
     */
    public function void(int $now): array
    {
        $voided = [];

        foreach ($this->unsettled() as $offerId) {
            try {
                $number = $this->offers->void($offerId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Price offer %d could not be voided: %s', $offerId, $e->getMessage()), ['exception' => $e]);

                continue;
            }

            if (null !== $number) {
                $voided[] = $number;
            }
        }

        return $voided;
    }

    /**
     * P122: the state changes whether or not the mail leaves — the offer and
     * its reminder told what silence means. A mail that did not leave is a
     * finding (P129).
     *
     * @return list<PriceOfferDue>
     */
    public function lapse(int $now): array
    {
        $lapsed = [];
        $due = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_subscription_price WHERE state = 'open' AND mailSentAt > 0 AND deadlineAt <= ? ORDER BY id",
            [$now],
        ));

        foreach ($due as $offerId) {
            try {
                $lapse = $this->offers->lapse($offerId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('Price offer %d could not lapse: %s', $offerId, $e->getMessage()), ['exception' => $e]);

                continue;
            }

            if (null === $lapse) {
                continue;
            }

            $lapsed[] = $lapse;

            if ($this->notify($lapse->event, $lapse->subscriptionId, $lapse->oldUnitNet, $lapse->newUnitNet, $lapse->effectiveAt, $lapse->deadlineAt)) {
                $this->offers->answerSent($lapse->offerId, $now);
            } else {
                $this->logger->error(\sprintf('The price offer of subscription %s ran out without an answer, but its mail did not go out. The customer has to be told by hand.', $lapse->subscriptionNumber));
            }
        }

        return $lapsed;
    }

    /**
     * P118.
     *
     * @return list<PriceOfferDue> the reminders sent
     */
    public function remind(int $now): array
    {
        $sent = [];
        $open = array_map('intval', $this->connection->fetchFirstColumn(
            // Whether one is due — and not sent yet for this deadline — is
            // decided under the lock (remind()); here only the deadlines
            // within reach: the reminder day starts at 00:00, the deadline
            // ends at 23:59 — and a day to spare for daylight saving.
            "SELECT id FROM tl_consho_subscription_price WHERE state = 'open' AND mailSentAt > 0 AND deadlineAt > ? AND deadlineAt <= ? ORDER BY id",
            [$now, $now + (SubscriptionPriceCheck::REMINDER_DAYS + 2) * 86400],
        ));

        foreach ($open as $offerId) {
            try {
                $reminder = $this->offers->remind($offerId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The reminder of price offer %d could not be prepared: %s', $offerId, $e->getMessage()), ['exception' => $e]);

                continue;
            }

            if (null === $reminder) {
                continue;
            }

            if ($this->notify('reminder', $reminder->subscriptionId, $reminder->oldUnitNet, $reminder->newUnitNet, $reminder->effectiveAt, $reminder->deadlineAt)) {
                $sent[] = $reminder;

                continue;
            }

            $this->offers->unremind($offerId);
            $this->logger->warning(\sprintf('The reminder of the price offer of subscription %s did not go out. It is tried again every hour.', $reminder->subscriptionNumber));
        }

        return $sent;
    }

    /**
     * @return list<PriceOfferDue> the offers made, their mail out
     */
    public function offer(int $now): array
    {
        $candidates = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_subscription
              WHERE state IN ('active', 'suspended', 'paused') AND (endsAt IS NULL OR endsAt = 0) AND renewedBy = 0
                AND renewal IN ('sameTerm', 'indefinite')
              ORDER BY id",
        ));

        $made = [];

        foreach ($candidates as $subscriptionId) {
            try {
                $due = $this->offers->prepare($subscriptionId, $now);
            } catch (\Throwable $e) {
                $this->logger->error(\sprintf('The price offer of subscription %d could not be prepared: %s', $subscriptionId, $e->getMessage()), ['exception' => $e]);

                continue;
            }

            if (null === $due) {
                continue;
            }

            if ($this->notify($due->event, $due->subscriptionId, $due->oldUnitNet, $due->newUnitNet, $due->effectiveAt, $due->deadlineAt)) {
                try {
                    $this->offers->sent($due->offerId, $now);
                    $made[] = $due;
                } catch (\Throwable $e) {
                    // The mail is out, the mark is not: prepare() clears the
                    // row after an hour and the offer is made anew.
                    $this->logger->error(\sprintf('The price offer of subscription %s was mailed, but could not be marked as sent: %s', $due->subscriptionNumber, $e->getMessage()), ['exception' => $e]);
                }

                continue;
            }

            // P135: silence has consequences, so an offer the customer did
            // not get was never made. The next hour tries anew.
            $this->offers->withdraw($due->offerId);
            $this->logger->warning(\sprintf('The price offer of subscription %s was taken back: its mail did not go out. It is tried again every hour.', $due->subscriptionNumber));
        }

        return $made;
    }

    /**
     * One of the mails of a price offer to the customer, with the operator's
     * copy where P137 says.
     *
     * @return bool whether the customer's mail left
     */
    public function notify(string $event, int $subscriptionId, string $oldUnitNet, string $newUnitNet, int $effectiveAt, int $deadlineAt): bool
    {
        try {
            $subscription = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);
            $order = false === $subscription ? false : $this->connection->fetchAssociative(
                'SELECT * FROM tl_consho_order WHERE id = ? OR subscription = ? ORDER BY createdAt DESC, id DESC LIMIT 1',
                [(int) $subscription['firstOrder'], $subscriptionId],
            );
            $viewUrl = false === $order ? null : $this->links->forMail($order);

            if (false === $subscription || false === $order || null === $viewUrl) {
                $this->logger->warning(\sprintf('The price offer mail of subscription %d was not sent: the shop has no order page.', $subscriptionId));

                return false;
            }

            $answerUrl = $this->answerUrl($subscription, $order) ?? '';

            // The answer is given under "Meine Abos" (P119): without that page
            // the customer could not answer, and silence has consequences
            // (P122) — so no offer goes out; the subscription runs on at its
            // frozen price.
            if (\in_array($event, ['offer', 'reminder'], true)) {
                if ('' === $answerUrl) {
                    $this->logger->warning(\sprintf('The price offer mail of subscription %s was not sent: the shop names no "Meine Abos" page to answer on.', (string) $subscription['subscriptionNumber']));

                    return false;
                }

                // Straight to the page that asks.
                $answerUrl .= (str_contains($answerUrl, '?') ? '&' : '?').http_build_query([MySubscriptionsFlow::PRICE_OFFER_PARAM => $subscriptionId]);
            }

            return $this->mail->priceOffer($event, (int) $order['id'], [
                'subscription' => (string) $subscription['subscriptionNumber'],
                'product' => (string) $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE id = ?', [(int) $subscription['orderItem']]),
                ...$this->amounts($subscription, $oldUnitNet, $newUnitNet, (string) $order['language']),
                'effectiveAt' => $effectiveAt,
                'deadlineAt' => $deadlineAt,
                'business' => (bool) $subscription['businessCustomer'],
                'answerUrl' => $answerUrl,
            ], $viewUrl);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The price offer mail of subscription %d was not sent: %s', $subscriptionId, $e->getMessage()), ['exception' => $e]);

            return false;
        }
    }

    /**
     * P119: the answer from "Meine Abos", then its mail — the agreement
     * confirmed with a copy, or the cancellation confirmed as usual, with its
     * copy (P121, P137).
     */
    public function answer(int $offerId, int $subscriptionId, bool $accept, string $name, int $now): PriceOfferAnswered|null
    {
        $answered = $this->offers->answer($offerId, $subscriptionId, $accept, $name, $now);

        if (null === $answered) {
            return null;
        }

        $offer = $answered->offer;

        if ($answered->accepted) {
            if ($this->notify('accepted', $offer->subscriptionId, $offer->oldUnitNet, $offer->newUnitNet, $offer->effectiveAt, $offer->deadlineAt)) {
                $this->offers->answerSent($offer->offerId, $now);

                return $answered->mailed();
            }

            $this->logger->error(\sprintf('The price offer of subscription %s was accepted, but the confirmation did not go out. It has to be confirmed by hand.', $offer->subscriptionNumber));

            return $answered;
        }

        if (null !== $answered->declaration && null !== $answered->cancellation && $this->recorder->confirmRecorded($answered->declaration, $answered->cancellation)->confirmed) {
            $this->offers->answerSent($offer->offerId, $now);

            return $answered->mailed();
        }

        return $answered;
    }

    /**
     * Old and new amount of one period as the customer reads them, with what
     * the mail and "Meine Abos" say about tax.
     *
     * Shown like the conditions text and the reminder (P117): gross at the
     * rate of the newest bill, a business customer taxed at home included;
     * net only where no tax is charged (reverse charge). The customer reads
     * the same amount in the offer, on the subscription and on the bill.
     *
     * @param array<string, mixed> $subscription a row of tl_consho_subscription
     *
     * @return array{oldPrice: string, newPrice: string, priceKey: string, months: int, taxed: bool}
     */
    public function amounts(array $subscription, string $oldUnitNet, string $newUnitNet, string $language): array
    {
        $currency = (string) $subscription['currency'];
        $rate = (bool) $subscription['reverseCharge'] ? '0' : self::taxRateOf($this->connection, $subscription);
        $months = SubscriptionBiller::periodMonths($subscription);
        $shown = fn (string $unitNet): string => $this->money->format(self::periodAmount($unitNet, (string) $subscription['quantity'], $rate), $currency, $language);

        return [
            'oldPrice' => $shown($oldUnitNet),
            'newPrice' => $shown($newUnitNet),
            'priceKey' => 1 === $months ? 'perMonth' : (12 === $months ? 'perYear' : 'perMonths'),
            'months' => $months,
            'taxed' => !BigDecimal::of($rate)->isZero(),
        ];
    }

    /**
     * The amount of one period: net plus tax at the given rate — "at the
     * current rate" (P117); the follow-up taxes at the rate of its day.
     */
    public static function periodAmount(string $unitNet, string $quantity, string $taxRate): string
    {
        $net = BigDecimal::of($unitNet)->multipliedBy($quantity);

        return (string) $net->plus($net->multipliedBy($taxRate)->dividedBy(100, 8, RoundingMode::HalfUp))->toScale(4, RoundingMode::HalfUp);
    }

    /**
     * @return list<int> the offers not settled yet whose subscription may end before them
     */
    private function unsettled(): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT p.id FROM tl_consho_subscription_price p LEFT JOIN tl_consho_subscription s ON s.id = p.pid
              WHERE (p.state = 'open' OR (p.state = 'accepted' AND p.appliedAt = 0))
                AND (s.id IS NULL OR s.state = 'ended' OR s.renewedBy > 0 OR (s.endsAt > 0 AND s.endsAt <= p.effectiveAt))
              ORDER BY p.id",
        ));
    }

    /**
     * The tax rate of the subscription's newest product line, in percent.
     *
     * @param array<string, mixed> $subscription
     */
    public static function taxRateOf(Connection $connection, array $subscription): string
    {
        $rate = $connection->fetchOne(
            "SELECT i.taxRate FROM tl_consho_order_item i INNER JOIN tl_consho_order o ON o.id = i.pid
              WHERE i.id = ? OR (o.subscription = ? AND i.lineType = 'product')
              ORDER BY o.createdAt DESC, o.id DESC, i.id LIMIT 1",
            [(int) $subscription['orderItem'], (int) $subscription['id']],
        );

        return false === $rate || null === $rate ? '0' : (string) $rate;
    }

    /**
     * "Meine Abos" of the start point, where the answer is given — null where
     * the shop names no such page.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $order
     */
    private function answerUrl(array $subscription, array $order): string|null
    {
        if (null === $this->pageUrls) {
            return null;
        }

        try {
            $rootId = $this->source->rootOf((int) $subscription['shop'], (string) $order['language']);
            // Only a page the customer can open: published and in its window,
            // as the finding subscriptionsPageMissing reads it. A link into an
            // offline page would let the deadline run out unanswered.
            $pageId = null === $rootId ? 0 : (int) $this->connection->fetchOne(
                "SELECT target.id FROM tl_page root
                   JOIN tl_page target ON target.id = root.consho_subscriptionsPage
                  WHERE root.id = :root AND target.published = 1
                    AND (target.start = '' OR target.start <= :now)
                    AND (target.stop = '' OR target.stop > :now)",
                // The full minute, the way Contao compares start/stop.
                ['root' => $rootId, 'now' => (string) (intdiv(time(), 60) * 60)],
            );

            return $pageId > 0 ? $this->pageUrls->ofPageId($pageId) : null;
        } catch (\Throwable $e) {
            $this->logger->warning(\sprintf('The price offer mail of subscription %s goes without a link to "Meine Abos": %s', (string) $subscription['subscriptionNumber'], $e->getMessage()));

            return null;
        }
    }
}
