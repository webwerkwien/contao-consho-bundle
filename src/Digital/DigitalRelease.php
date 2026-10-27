<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherIssuer;

/**
 * Releases the digital lines of an order once they are due (F-4).
 *
 * ── When (F10, F16) ──────────────────────────────────
 *
 * Two conditions, both needed:
 *
 *  1. PAYMENT. A method that delivers before it is paid — its invoice comes
 *     with the shipment (`OnShipment`: "Auf Rechnung") — releases with the
 *     order. Every other method waits for the settled payment.
 *  2. WITHDRAWAL. A consumer who consented to the early start (F15), or a
 *     business customer, gets it at once. Otherwise the digital lines wait
 *     for the end of the withdrawal period, and the right of withdrawal
 *     stays (F16). A shop without a withdrawal period does not wait.
 *
 * ── What (F17) ───────────────────────────────────────────────────────────
 *
 * The release IS the delivery: one entitlement per open digital line, the
 * delivery handler asked to hand it out, and a delivery of exactly those
 * lines reported through DeliveryReported. So the invoice on shipment, the
 * status change and the delivery date on the invoice run the way block D
 * built them, and nothing here duplicates them.
 *
 * All of it in ONE transaction — entitlements, handler writes (a key taken
 * from the pool), the delivery and its report. A handler that cannot deliver
 * yet (an empty pool) leaves its line open for the next run.
 *
 * ── Who calls ────────────────────────────────────────────────────────────
 *
 * The checkout after the confirmation mail (so the consent confirmation on a
 * durable medium goes out first, § 7 Abs. 3 FAGG, § 312f BGB), PaymentRecorded
 * after a settled payment, and DigitalReleaseCron for everything that waits.
 * Idempotent: a line released is no longer open, so a second call finds
 * nothing to do.
 */
class DigitalRelease
{
    /** The invoice issued in the current release, archived after its commit. */
    private int|null $toArchive = null;

    /** @var list<int> the vouchers the current release issued, mailed after its commit (WG-4) */
    private array $issued = [];

    /** How many content and service lines the current release unlocked. */
    private int $unlocked = 0;

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $paymentHandlers,
        private readonly DigitalProductSettings $settings,
        private readonly DeliveryHandlers $handlers,
        private readonly DeliveryReported $deliveries,
        // The invoice a release issues is archived after the release's commit
        // (Abo-4). Required: an optional one would be missing in
        // silence.
        private readonly DocumentArchiverInterface $archiver,
        // Block Wertgutscheine (WG-4): the voucher lines become vouchers here.
        // Required for the same reason as the archiver.
        private readonly VoucherIssuer $vouchers,
        // For a release that no other mail announces (F-7, F20).
        private readonly OrderPageLink|null $links = null,
        private readonly OrderEventMail|null $mail = null,
        // Nachlese E5a: an unpaid one-time purchase rests.
        private readonly PurchaseDelinquency|null $purchases = null,
    ) {
    }

    /**
     * @param bool $notify send "Ihre digitalen Inhalte sind bereit" — for the
     *                     cron; the checkout and the payment have mails of
     *                     their own that say it (F20). Sent regardless when
     *                     the release issued the invoice, with it attached.
     *
     * @return int how many lines were released
     */
    public function releaseDue(int $orderId, int $now, bool $notify = false): int
    {
        /*
         * Back to exactly the level we found, on any failure. A
         * single rollBack() at level 2 only releases a savepoint in DBAL 4,
         * and the cron would then run every later order inside a transaction
         * that is never committed.
         */
        $level = $this->connection->getTransactionNestingLevel();
        $this->connection->beginTransaction();

        try {
            $released = $this->releaseLocked($orderId, $now);

            // Released late — a key pool refilled — into an order that already
            // rests: it rests with its siblings at once (Nachlese).
            // Only joining, never lifting or starting a rest: those are
            // SubscriptionDelinquencyRun's, which mails them — a change decided here would be swallowed without a mail.
            if ($released > 0) {
                $this->purchases?->joinRest($orderId, $now);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            // A rollback that throws as well — the connection is gone — must
            // not replace the cause on its way to the caller. Nothing more can be undone on such a connection anyway.
            try {
                while ($this->connection->getTransactionNestingLevel() > $level) {
                    $this->connection->rollBack();
                }
            } catch (\Throwable) {
            }

            // Rolled back: nothing to archive, nothing issued.
            $this->toArchive = null;
            $this->issued = [];
            $this->unlocked = 0;

            throw $e;
        }

        $pending = $this->toArchive;
        $issued = $this->issued;
        $unlocked = $this->unlocked;
        $this->toArchive = null;
        $this->issued = [];
        $this->unlocked = 0;

        // The invoice the release issued, after this commit rather than
        // inside it (Abo-4). archive() never throws.
        if (null !== $pending) {
            $this->archiver->archive($pending);
        }

        /*
         * The mail also goes out where the release issued the invoice (an
         * "on shipment" method such as "Auf Rechnung"; DL). The release
         * runs after the confirmation, so nothing else would carry that
         * invoice to the customer — the order page was the only place.
         */
        if ($released > 0 && null !== $this->links && null !== $this->mail) {
            $order = $this->orders->order($orderId);
            $viewUrl = null === $order ? null : $this->links->forMail($order);
            $digitalMail = $unlocked > 0 && ($notify || null !== $pending);

            if (null !== $viewUrl && $digitalMail) {
                $this->mail->digitalReleased($orderId, $now, $viewUrl, $pending);
            }

            /*
             * The vouchers, always (WG4): the mail is how they reach the
             * buyer, with a PDF each to pass on. It carries the invoice the
             * release issued unless the mail above already did.
             */
            if (null !== $viewUrl && [] !== $issued) {
                $this->mail->vouchersIssued($orderId, $issued, $viewUrl, $digitalMail ? null : $pending);
            }
        }

        return $released;
    }

    /**
     * The moment the digital lines of this order become due, or null while
     * the payment is still missing. For the cron and for the order page.
     *
     * @param array<string, mixed> $order
     */
    public function dueAt(array $order): int|null
    {
        if (!$this->paymentAllows($order)) {
            return null;
        }

        /*
         * The consent lapses the right of withdrawal only once the
         * confirmation on a durable medium is with the consumer (§ 18 Abs. 1
         * Z 11 lit. c FAGG, § 356 Abs. 6 Nr. 2 lit. d BGB). Until
         * the confirmation mail has left, the consent does not count, and the
         * order waits like one without it: for a resend from the back end
         * (then at once), or for the end of the period — which runs anyway
         * (§ 11 Abs. 2 Z 3 FAGG). Second review, S-A: waiting for the mail
         * alone left a paying customer without anything for good.
         */
        $consentOnly = self::given($order['digitalConsentAt'] ?? null) && !(bool) ($order['businessCustomer'] ?? false);
        $ends = $consentOnly && !self::given($order['confirmationSentAt'] ?? null)
            ? $this->periodOf($order)
            : $this->periodEndsAt($order);

        return null === $ends ? (int) $order['createdAt'] : $ends + 1;
    }

    /**
     * The last second of the withdrawal period this release waits for — null
     * where it does not wait: consent, a business customer, a shop without a
     * period.
     *
     * The period for digital content runs from the conclusion of the
     * contract (§ 11 Abs. 2 Z 3 FAGG: „mit dem Tag des Vertragsabschlusses";
     * § 355 Abs. 2 BGB: „beginnt mit Vertragsschluss" — both read at the
     * primary text on 2026-09-29), not from a delivery: there is none before.
     * Counted the way block D counts its window (WithdrawalWindow): to the
     * END of the day, in the installation's time zone, plus the shop's
     * grace days. A day too late costs nothing; a day too early releases
     * while the right still stands.
     *
     * @param array<string, mixed> $order
     */
    public function periodEndsAt(array $order): int|null
    {
        if ((bool) ($order['businessCustomer'] ?? false) || self::given($order['digitalConsentAt'] ?? null)) {
            return null;
        }

        return $this->periodOf($order);
    }

    /**
     * The end of the shop's period for this order, whatever the consent.
     *
     * @param array<string, mixed> $order
     */
    private function periodOf(array $order): int|null
    {
        $shop = $this->connection->fetchAssociative('SELECT withdrawalDays, withdrawalGraceDays FROM tl_consho_shop WHERE id = ?', [(int) $order['shop']]);
        $days = false === $shop ? 0 : (int) $shop['withdrawalDays'];

        if ($days <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@'.(int) $order['createdAt']))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->modify(\sprintf('+%d days', $days + max(0, (int) $shop['withdrawalGraceDays'])))
            ->setTime(23, 59, 59)
            ->getTimestamp();
    }

    /**
     * Whether the payment side lets the release go (F10), for the order page
     * and the confirmation.
     *
     * @param array<string, mixed> $order
     */
    public function paymentReady(array $order): bool
    {
        return $this->paymentAllows($order);
    }

    private function releaseLocked(int $orderId, int $now): int
    {
        $order = $this->orders->lockOrder($orderId);

        if (null === $order || self::given($order['cancelledAt'] ?? null)) {
            return 0;
        }

        /*
         * A withdrawal that is declared and not yet decided: the
         * customer may have left the contract. Nothing is supplied until the
         * operator has decided — an accepted one then takes the lines out of
         * openGoods, a rejected one lets them through.
         */
        if ((int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_withdrawal WHERE pid = ? AND state = 'received'", [$orderId]) > 0) {
            return 0;
        }

        $delivered = [];

        /*
         * Vouchers (WG-4): once paid, whatever the payment method (WG6), and
         * without waiting for the withdrawal period — the code is money, not
         * a performance begun early. Delivered in the same report as the
         * content below.
         */
        $vouchers = $this->openLines($orderId, true);

        if ([] !== $vouchers && self::given($order['paymentSettledAt'] ?? null)) {
            foreach ($this->vouchers->issue($order, $vouchers, $now) as $ids) {
                array_push($this->issued, ...$ids);
            }

            foreach ($vouchers as $itemId => $line) {
                $delivered[$itemId] = $line['quantity'];
            }
        }

        $digital = $this->openLines($orderId, false);
        $dueAt = [] === $digital ? null : $this->dueAt($order);

        if (null === $dueAt || $now < $dueAt) {
            $digital = [];
        }

        foreach ($digital as $itemId => $line) {
            $settings = $this->settings->of($line['product']);

            /*
             * A service delivers nothing (DL1): its release is the
             * entitlement alone — "freigeschaltet" —, whatever delivery type
             * the product still carries from an earlier kind of its type.
             * The finding "Produkt passt nicht zu seiner Art" names that.
             */
            if (ProductKind::Service === $line['kind']) {
                $settings['type'] = '';
            }

            /*
             * Abo-3 (P21): a subscription line's entitlement ends with the
             * paid period, not after `entitlementMonths` — that belongs to the
             * one-time purchase. A line bought before Abo-3 has no
             * subscription (P22) and keeps the old reckoning.
             */
            $subscription = $this->connection->fetchAssociative('SELECT id, periodUntil, state, predecessor FROM tl_consho_subscription WHERE orderItem = ?', [$itemId]);

            // Released while the subscription rests (a key pool refilled
            // late): it rests from the start, not only from the next run of
            // the cron (Abo-5).
            $suspendedAt = false !== $subscription && 'suspended' === (string) $subscription['state'] ? $now : null;

            if (false !== $subscription) {
                $validUntil = (int) $subscription['periodUntil'];
            } else {
                $validUntil = $settings['months'] > 0
                    ? (new \DateTimeImmutable('@'.$now))->modify(\sprintf('+%d months', $settings['months']))->getTimestamp()
                    : null;
            }

            /*
             * "Lizenz verlängern" (Abo-7b, P69): the line continues another
             * subscription. Its entitlement moves over — same row, same keys,
             * same id the handler and any module know — instead of a new one
             * with new keys to enter. Revoked stays revoked; only owner and
             * end move. Without one (the old line was never released), the
             * line is released like any other.
             */
            if (false !== $subscription && (int) $subscription['predecessor'] > 0
                && $this->takeOver((int) $subscription['predecessor'], (int) $subscription['id'], $orderId, $itemId, (int) $validUntil, $suspendedAt, $now)
            ) {
                $delivered[$itemId] = $line['quantity'];
                ++$this->unlocked;

                continue;
            }

            // A savepoint per line: a handler that cannot deliver takes back
            // whatever it wrote along with the row (DeliveryHandlerInterface).
            $this->connection->beginTransaction();

            try {
                $handedOut = $this->releaseLine($orderId, $itemId, $line, $settings, $order, $now, $validUntil, false === $subscription ? 0 : (int) $subscription['id'], $suspendedAt);
            } catch (\Throwable $e) {
                // As in releaseDue(): the cause, not a failing rollback.
                try {
                    $this->connection->rollBack();
                } catch (\Throwable) {
                }

                throw $e;
            }

            if (!$handedOut) {
                $this->connection->rollBack();

                continue;
            }

            $this->connection->commit();
            $delivered[$itemId] = $line['quantity'];
            ++$this->unlocked;
        }

        return $this->report($orderId, $delivered, $now);
    }

    /**
     * The renewed subscription's entitlement, moved onto this line (P69): the
     * new order's page shows it from now on, the renewal's periods extend it.
     *
     * @return bool whether there was one to move
     */
    private function takeOver(int $predecessor, int $subscription, int $orderId, int $itemId, int $validUntil, int|null $suspendedAt, int $now): bool
    {
        $moved = (int) $this->connection->executeStatement(
            'UPDATE tl_consho_entitlement SET pid = ?, orderItem = ?, subscription = ?, validUntil = ?, tstamp = ? WHERE subscription = ?',
            [$orderId, $itemId, $subscription, $validUntil, $now, $predecessor],
        );

        if ($moved > 0 && null !== $suspendedAt) {
            $this->connection->executeStatement(
                'UPDATE tl_consho_entitlement SET suspendedAt = ? WHERE subscription = ? AND suspendedAt IS NULL',
                [$suspendedAt, $subscription],
            );
        }

        return $moved > 0;
    }

    /**
     * One line inside its savepoint: the entitlement row, and the handler.
     *
     * @param array{product: int, quantity: string, kind: ProductKind} $line
     * @param array{type: string, months: int}                        $settings
     * @param array<string, mixed>                  $order
     */
    private function releaseLine(int $orderId, int $itemId, array $line, array $settings, array $order, int $now, int|null $validUntil, int $subscription, int|null $suspendedAt = null): bool
    {
        $this->connection->insert('tl_consho_entitlement', [
            'pid' => $orderId,
            'tstamp' => $now,
            'orderItem' => $itemId,
            'member' => (int) ($order['member'] ?? 0),
            'product' => $line['product'],
            'quantity' => $line['quantity'],
            'deliveryType' => $settings['type'],
            'validFrom' => $now,
            'validUntil' => $validUntil,
            'subscription' => $subscription,
            'suspendedAt' => $suspendedAt,
        ]);

        $id = (int) $this->connection->lastInsertId();
        $entitlement = new Entitlement($id, $orderId, $itemId, (int) ($order['member'] ?? 0), $line['product'], $line['quantity'], $settings['type'], $now, $validUntil, false, $subscription, $suspendedAt);

        /*
         * No handler, or one that is gone: the entitlement alone. It
         * still stands for "bought, released" on the order page, and a
         * module installed later can serve it.
         */
        return !$this->handlers->has($settings['type']) || $this->handlers->get($settings['type'])->release($entitlement);
    }

    /**
     * @param array<int, string> $delivered order item id => quantity
     */
    private function report(int $orderId, array $delivered, int $now): int
    {
        if ([] === $delivered) {
            return 0;
        }

        $this->connection->insert('tl_consho_delivery', ['pid' => $orderId, 'tstamp' => $now, 'shippedAt' => $now, 'carrier' => '', 'trackingNumber' => '']);
        $deliveryId = (int) $this->connection->lastInsertId();

        foreach ($delivered as $itemId => $quantity) {
            $this->connection->insert('tl_consho_delivery_item', ['pid' => $deliveryId, 'tstamp' => $now, 'orderItem' => $itemId, 'quantity' => $quantity]);
        }

        // Nested: DBAL turns it into a savepoint. No mail — the release
        // happens on the back of a mail of its own (F20).
        $report = $this->deliveries->report($deliveryId, $now, false);
        // One release, one delivery, at most one invoice.
        $this->toArchive = $report->toArchive;

        if ('reported' !== $report->outcome) {
            throw new \RuntimeException(\sprintf('The electronic delivery of order %d could not be reported.', $orderId));
        }

        return \count($delivered);
    }

    /**
     * The open lines released here: content and services, or — apart,
     * because they follow other rules (WG6) — vouchers, which become codes
     * instead of entitlements (VoucherIssuer).
     *
     * @return array<int, array{product: int, quantity: string, kind: ProductKind}> order item id => article, open quantity and kind
     */
    private function openLines(int $orderId, bool $vouchers): array
    {
        $open = $this->balances->of($orderId)?->openGoods;

        if (null === $open || [] === $open) {
            return [];
        }

        $lines = [];
        $kinds = $vouchers ? "kind = 'voucher'" : "kind NOT IN ('physical', 'voucher')";

        foreach ($this->connection->fetchAllAssociative("SELECT id, product, kind FROM tl_consho_order_item WHERE pid = ? AND lineType = 'product' AND ".$kinds, [$orderId]) as $row) {
            $id = (int) $row['id'];

            if (isset($open[$id])) {
                $lines[$id] = ['product' => (int) $row['product'], 'quantity' => $open[$id], 'kind' => ProductKind::stored((string) $row['kind'])];
            }
        }

        return $lines;
    }

    /**
     * Whether a nullable column of the order row holds a value.
     *
     * The order arrives in two shapes: straight from the database (NULL) and
     * through PaymentChangeRepositoryInterface::lockOrder(), which casts
     * EVERY value to a string — NULL becomes ''. With `null !== $order['cancelledAt']` every order read
     * under the lock counted as cancelled, and nothing was ever released.
     * The unit tests had handed in real NULLs — the double answered what its
     * author expected, not what the repository returns (rule 25).
     */
    private static function given(mixed $value): bool
    {
        return null !== $value && '' !== (string) $value;
    }

    /**
     * @param array<string, mixed> $order
     */
    private function paymentAllows(array $order): bool
    {
        if (self::given($order['paymentSettledAt'] ?? null)) {
            return true;
        }

        $method = $this->methods->byId((int) ($order['paymentMethod'] ?? 0));

        return null !== $method
            && $this->paymentHandlers->has($method->type)
            && DocumentTrigger::OnShipment === $this->paymentHandlers->get($method->type)->documentTrigger($method->config);
    }
}
