<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Address\Address;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Address\VatIdValidatorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Checkout\MemberCheckoutDefaults;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\OrderDraft;
use Webwerkwien\ContaoConshoBundle\Order\OrderFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineDraft;

/**
 * Bills one period of a subscription (Abo-4; A5, A6, A9, P24–P29, P31).
 *
 * One transaction, all or nothing:
 *
 *  1. the subscription row, locked and read again — two runs at once must
 *     not both find the period due;
 *  2. the follow-up order through OrderFactory, with a FROZEN line: the net,
 *     licences, class and terms of the purchase (A9), name and item number of
 *     the first order line; the billing address of the ACCOUNT where complete
 *     (P74), else, like delivery address, language and VAT ID, of the LAST
 *     order of the subscription (P29); "on account" (P24) — or the method of
 *     the mandate, which SubscriptionCharger charges after the commit
 *     (Abo-4b, P94); no consent, no terms accepted — a follow-up opens no
 *     withdrawal period (A12);
 *  3. the line reported as delivered: that is what issues the invoice of an
 *     "on account" method (DocumentTrigger::OnShipment) and moves the status;
 *     a charging method issues by its own trigger, as at the purchase (P97).
 *     No new entitlement and no key (P27) — which is why this does not go
 *     through DigitalRelease;
 *  4. the period moved on, its boundaries from the calendar;
 *  5. every entitlement of the subscription valid to the new end (A6).
 *
 * Not billed: before `nextBillingAt`; from the end of the minimum term on
 * until SubscriptionLifecycle opens the next phase (Abo-6); at or after a
 * known end (P49); a subscription neither active nor resting; a shop without
 * an "on account" method (a finding says so, P24) — with a mandate as well:
 * a failed charge falls back on it (P95). One payment per term
 * (interval 0) is billed again only after a same-term renewal (P45).
 */
class SubscriptionBiller
{
    /** The invoice issued in the current transaction, archived after its commit. */
    private int|null $toArchive = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly SubscriptionLockInterface $lock,
        private readonly OrderFactoryInterface $orders,
        private readonly SubscriptionBillingMethod $billing,
        private readonly DeliveryReported $deliveries,
        private readonly SubscriptionCalendar $calendar,
        private readonly DocumentArchiverInterface $archiver,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // The account's billing address (Abo-Nachlese, P74). Optional for the
        // tests built by hand; the container wires it.
        private readonly MemberCheckoutDefaults|null $members = null,
        // VIES per period for reverse charge (replacing P26's "no check").
        // Optional like the above.
        private readonly VatIdValidatorInterface|null $vatIds = null,
    ) {
    }

    public function billDue(int $subscriptionId, int $now): BilledPeriod|null
    {
        // Back to exactly the level we found (DigitalRelease): in
        // DBAL 4 a single rollBack() at level 2 only releases a savepoint.
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $billed = $this->billLocked($subscriptionId, $now);
            $this->connection->commit();
        } catch (\Throwable $e) {
            while ($this->connection->getTransactionNestingLevel() > $level) {
                $this->connection->rollBack();
            }

            // Rolled back: nothing to archive.
            $this->toArchive = null;

            throw $e;
        }

        $pending = $this->toArchive;
        $this->toArchive = null;

        // The invoice's PDF after this commit, not inside it —
        // the cron calls outside any transaction, so this is the real one.
        // archive() never throws; a failure is recorded on the document.
        if (null !== $pending) {
            $this->archiver->archive($pending);
        }

        return $billed;
    }

    /**
     * Whether a row is due at this moment — the same question the cron asks
     * in SQL, asked again under the lock.
     *
     * @param array<string, mixed> $row
     */
    public static function isDue(array $row, int $now): bool
    {
        $next = (int) $row['nextBillingAt'];
        $endsAt = (int) ($row['endsAt'] ?? 0);

        // A resting subscription is billed too (Abo-5, P36): the contract runs
        // on, and a payment must not bring every missed period at once.
        //
        // Within the minimum term only up to its end; the phase after it is
        // SubscriptionLifecycle's to open (Abo-6): an open-ended subscription
        // is billed without an upper bound (P46), a renewed term moved the end
        // on (P45). A known end stops billing at it (P49).
        return \in_array((string) $row['state'], ['active', 'suspended'], true)
            && $next > 0
            && $next <= $now
            && ('indefinite' === (string) ($row['phase'] ?? 'minimum') || $next < (int) $row['minimumEndsAt'])
            && (0 === $endsAt || $next < $endsAt);
    }

    /**
     * The months of one billed period: the interval, or the whole term for a
     * subscription paid once per term (interval 0) — which gets a new period
     * only with a same-term renewal (Abo-6, P45).
     *
     * @param array<string, mixed> $row
     */
    public static function periodMonths(array $row): int
    {
        $interval = (int) $row['billingInterval'];

        return $interval > 0 ? $interval : SubscriptionRuleValidator::termMonths((int) $row['termLength'], (string) $row['termUnit']);
    }

    private function billLocked(int $subscriptionId, int $now): BilledPeriod|null
    {
        $subscription = $this->lock->lock($subscriptionId);

        if (null === $subscription || !self::isDue($subscription, $now)) {
            return null;
        }

        $last = $this->lastOrder($subscriptionId, (int) $subscription['firstOrder']);
        $shop = $this->connection->fetchAssociative('SELECT country, vatId, fallbackLanguage, taxAtDestination FROM tl_consho_shop WHERE id = ?', [(int) $subscription['shop']]);

        if (null === $last || false === $shop) {
            throw new \RuntimeException(\sprintf('Subscription %s has lost its first order or its shop.', $subscription['subscriptionNumber']));
        }

        $language = (string) $last['language'];
        $method = $this->billing->forShop((int) $subscription['shop'], $language, (string) $shop['fallbackLanguage']);

        if (null === $method) {
            // P24: the finding says it on the start page; the log says it
            // where someone looks for a missing order.
            $this->logger->warning(\sprintf('Subscription %s was not billed: the shop has no payment method "on account".', $subscription['subscriptionNumber']));

            return null;
        }

        // Abo-4b: the method of the mandate, where it still charges (P99).
        $charging = $this->billing->charging($subscription, $language, (string) $shop['fallbackLanguage']);
        $method = $charging ?? $method;

        [$periodFrom, $periodUntil] = $this->nextPeriod($subscription);
        $firstLine = $this->connection->fetchAssociative('SELECT productName, sku FROM tl_consho_order_item WHERE id = ?', [(int) $subscription['orderItem']]);

        /*
         * The billing address the account has TODAY (Abo-Nachlese, P74,
         * replacing P29 for it), the last order's where the account lacks what
         * an invoice needs. The salutation stays the typed one — the account
         * has none Consho takes over (MemberCheckoutDefaults). Delivery
         * address, language and VAT ID stay the last order's: the VAT ID goes
         * with the frozen reverse charge (P26, P74).
         */
        $account = $this->members?->billingOf((int) $subscription['member'], (string) $last['email']);

        /*
         * Another country in the account (Abo-Nachlese): for digital goods the billing country is the tax
         * country, and the checkout that once checked it — served, a rate,
         * reverse charge — does not run here. The last order's address stays;
         * the finding "Land gewechselt" leaves the move to the operator.
         *
         * Not for a service (DL4): taxed in the shop's country, a move
         * changes nothing the checkout would have checked.
         */
        if (null !== $account && ProductKind::Service !== ProductKind::stored((string) $subscription['kind']) && $account->country !== (string) $last['billingCountry']) {
            $this->logger->warning(\sprintf('Subscription %s was billed to its previous address: the account names another country (%s instead of %s).', $subscription['subscriptionNumber'], $account->country, $last['billingCountry']));
            $account = null;
        }

        $billing = $account?->with(['salutation' => (string) $last['salutation']])
            ?? new Address(
                company: (string) $last['company'],
                salutation: (string) $last['salutation'],
                firstname: (string) $last['firstname'],
                lastname: (string) $last['lastname'],
                street: (string) $last['billingStreet'],
                postal: (string) $last['billingPostal'],
                city: (string) $last['billingCity'],
                state: (string) $last['billingState'],
                country: (string) $last['billingCountry'],
                phone: (string) $last['phone'],
                email: (string) $last['email'],
            );

        [$reverseCharge, $vatCheck] = $this->reverseCharge($subscription, $last, (string) $shop['vatId']);

        $orderId = $this->orders->create(
            new OrderDraft(
                shopId: (int) $subscription['shop'],
                currency: (string) $subscription['currency'],
                billing: $billing,
                shipping: new Address(
                    company: (string) $last['shippingCompany'],
                    firstname: (string) $last['shippingFirstname'],
                    lastname: (string) $last['shippingLastname'],
                    street: (string) $last['shippingStreet'],
                    postal: (string) $last['shippingPostal'],
                    city: (string) $last['shippingCity'],
                    state: (string) $last['shippingState'],
                    country: (string) $last['shippingCountry'],
                ),
                shopCountry: (string) $shop['country'],
                memberId: (int) $subscription['member'],
                customerVatId: (string) $last['vatId'],
                vatCheck: $vatCheck,
                language: $language,
                fallbackLanguage: (string) $shop['fallbackLanguage'],
                shippingMethod: (int) $last['shippingMethod'],
                shippingMethodTitle: (string) $last['shippingMethodTitle'],
                paymentMethod: $method->id,
                paymentMethodTitle: $method->title,
                paymentInstructions: (string) ($method->config['instructions'] ?? ''),
                shopVatId: (string) $shop['vatId'],
                shippingPhone: (string) $last['shippingPhone'],
                businessCustomer: (bool) $subscription['businessCustomer'],
                subscriptionId: $subscriptionId,
                frozenReverseCharge: $reverseCharge,
                servicePeriodFrom: $periodFrom,
                servicePeriodUntil: $periodUntil,
                // Block R (R3): as the shop says it today — over the
                // threshold, every later supply is taxed at destination.
                taxAtDestination: (bool) $shop['taxAtDestination'],
            ),
            [OrderLineDraft::frozen(
                (int) $subscription['product'],
                (string) $subscription['quantity'],
                (string) $subscription['unitNet'],
                (int) $subscription['taxClass'],
                (int) $subscription['rule'],
                (string) $subscription['terms'],
                false === $firstLine ? '' : (string) $firstLine['productName'],
                false === $firstLine ? '' : (string) $firstLine['sku'],
                (string) $subscription['kind'],
            )],
            $now,
        );

        $this->reportDelivered($orderId, $now);

        $this->connection->update('tl_consho_subscription', [
            'tstamp' => $now,
            'periodFrom' => $periodFrom,
            'periodUntil' => $periodUntil,
            'nextBillingAt' => $periodUntil,
        ], ['id' => $subscriptionId]);

        // A6: extended with the follow-up order, paid or not. Revoked stays
        // revoked — only the end moves. An entitlement not released yet
        // (prepayment unpaid, an empty key pool) has no row; its release
        // reads the new periodUntil then (P21).
        $this->connection->executeStatement(
            'UPDATE tl_consho_entitlement SET validUntil = ?, tstamp = ? WHERE subscription = ?',
            [$periodUntil, $now, $subscriptionId],
        );

        return new BilledPeriod($subscriptionId, (string) $subscription['subscriptionNumber'], $orderId, $periodFrom, $periodUntil, null !== $charging);
    }

    /**
     * Reverse charge for this period: the purchase's decision, checked again.
     *
     * A VAT ID valid at the purchase may be withdrawn since, and reverse charge
     * on an invalid one is tax the shop owes. So every follow-up order asks
     * VIES again, and the answer goes onto the order as the evidence the
     * checkout's answer goes onto the first one:
     *
     *  - valid: reverse charge, as frozen;
     *  - invalid: taxed — the safe direction (VatIdStatus) — and the finding
     *    "UID ungültig" names the subscription, as the operator has to clarify
     *    it with the customer; the next period asks again;
     *  - not reachable: the frozen decision stands, as the checkout does not
     *    block on it either (C15), and the log says the check is owed.
     *
     * Asked under the subscription's lock: VIES answers within its timeout,
     * and a second run must not bill the same period meanwhile.
     *
     * @param array<string, mixed> $subscription
     * @param array<string, mixed> $last
     *
     * @return array{bool, VatIdCheckResult|null}
     */
    private function reverseCharge(array $subscription, array $last, string $shopVatId): array
    {
        $vatId = trim((string) $last['vatId']);

        if (!(bool) $subscription['reverseCharge'] || null === $this->vatIds || '' === $vatId) {
            return [(bool) $subscription['reverseCharge'], null];
        }

        $check = $this->vatIds->check($vatId, '' === trim($shopVatId) ? null : $shopVatId);

        if (VatIdStatus::Invalid === $check->status) {
            $this->logger->warning(\sprintf('Subscription %s was billed with VAT: VIES reports the VAT ID %s as invalid.', $subscription['subscriptionNumber'], $vatId));

            return [false, $check];
        }

        if (VatIdStatus::Unavailable === $check->status) {
            $this->logger->warning(\sprintf('Subscription %s was billed with reverse charge from the purchase: VIES could not check the VAT ID %s.', $subscription['subscriptionNumber'], $vatId));
        }

        return [true, $check];
    }

    /**
     * The period after the current one, both boundaries from the calendar
     * (P17, P28): the start plus k and k+1 intervals, where k intervals end
     * the current period. `nextBillingAt` only says WHEN to bill — a date
     * moved by hand must not move the period.
     *
     * @param array<string, mixed> $subscription
     *
     * @return array{int, int}
     */
    private function nextPeriod(array $subscription): array
    {
        $interval = self::periodMonths($subscription);
        // After a pause the next period starts where the pause ends (P104),
        // not where the last paid one did.
        $current = max((int) $subscription['periodUntil'], (int) ($subscription['pauseUntil'] ?? 0));

        // From the start, whatever the interval was before (P17, P46): a
        // yearly subscription turned monthly keeps its anchor, as every term
        // is a whole number of months. The guard (100 years) keeps a broken
        // row from looping for ever.
        for ($k = 1; $k <= 1200; ++$k) {
            $boundary = $this->calendar->boundary($subscription, $k * $interval);

            if ($boundary >= $current) {
                return [$boundary, $this->calendar->boundary($subscription, ($k + 1) * $interval)];
            }
        }

        throw new \RuntimeException(\sprintf('Subscription %s has a period that no boundary reaches.', $subscription['subscriptionNumber']));
    }

    /**
     * The subscription's newest order: a follow-up, or the purchase itself.
     *
     * @return array<string, mixed>|null
     */
    private function lastOrder(int $subscriptionId, int $firstOrder): array|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT * FROM tl_consho_order WHERE id = ? OR subscription = ? ORDER BY createdAt DESC, id DESC LIMIT 1',
            [$firstOrder, $subscriptionId],
        );

        return false === $row ? null : $row;
    }

    /**
     * The follow-up's product line as one delivery, reported without the
     * "shipped" mail — the invoice goes with a mail of its own (P30).
     */
    private function reportDelivered(int $orderId, int $now): void
    {
        $items = $this->connection->fetchAllKeyValue(
            "SELECT id, quantity FROM tl_consho_order_item WHERE pid = ? AND lineType = 'product'",
            [$orderId],
        );

        $this->connection->insert('tl_consho_delivery', ['pid' => $orderId, 'tstamp' => $now, 'shippedAt' => $now, 'carrier' => '', 'trackingNumber' => '']);
        $deliveryId = (int) $this->connection->lastInsertId();

        foreach ($items as $itemId => $quantity) {
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $deliveryId, 'tstamp' => $now, 'orderItem' => (int) $itemId, 'quantity' => (string) $quantity]);
        }

        $report = $this->deliveries->report($deliveryId, $now, false);

        if ('reported' !== $report->outcome) {
            throw new \RuntimeException(\sprintf('The follow-up order %d could not be reported as delivered (%s).', $orderId, $report->outcome));
        }

        $this->toArchive = $report->toArchive;
    }
}
