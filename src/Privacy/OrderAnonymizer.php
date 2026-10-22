<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Privacy;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Removes the personal data of one order whose retention period has ended
 * (D-5, L4).
 *
 * Anonymised, not deleted: numbers, amounts, tax, lines and dates stay, so the
 * number ranges stay unbroken and every total still adds up (spec 8). What
 * goes is everything that names or reaches the person:
 *
 *  - the order's name, company, addresses (street, postal code, city,
 *    state — the country stays: it decided the tax), e-mail, phone, date of
 *    birth, VAT ID and VIES identifier, and the link to the account;
 *  - the same fields in every document's snapshot;
 *  - withdrawals (name, e-mail, the customer's and the operator's texts),
 *    complaint notes, payment references, tracking numbers;
 *  - the versions of these records — Contao keeps the whole row in
 *    tl_version, and a version is a copy;
 *  - the order's tokens (a link from an old mail would open an empty order)
 *    and any basket that became this order;
 *  - the archived PDFs, after the transaction: a file cannot be rolled back,
 *    so it goes only once the database has.
 *
 * Only for orders past their period — the caller decides that
 * (RetentionPolicy). Within the period the document IS the accounting
 * voucher and must stay as issued.
 */
class OrderAnonymizer
{
    /** Emptied in the order and in the snapshot's `order`. */
    public const ORDER_FIELDS = [
        'salutation', 'firstname', 'lastname', 'email', 'phone', 'company',
        'billingStreet', 'billingPostal', 'billingCity', 'billingState',
        'shippingCompany', 'shippingFirstname', 'shippingLastname',
        'shippingStreet', 'shippingPostal', 'shippingCity', 'shippingState', 'shippingPhone',
        'dateOfBirth', 'vatId', 'vatIdRequestIdentifier',
    ];

    /** WA, A6: how long a withdrawal without an order is kept. */
    public const UNASSIGNED_WITHDRAWAL_YEARS = 3;

    /** Versions of these tables, by the order or by its children. */
    private const VERSIONED_CHILDREN = ['tl_consho_payment', 'tl_consho_delivery', 'tl_consho_complaint', 'tl_consho_withdrawal'];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return bool false when the order does not exist or was anonymised before
     */
    public function anonymize(int $orderId, int $now): bool
    {
        $paths = $this->connection->transactional(fn (): array|null => $this->anonymizeRows($orderId, $now));

        if (null === $paths) {
            return false;
        }

        $this->deleteFiles($paths);

        return true;
    }

    /**
     * The database part alone, for a caller with a transaction of its own
     * (CustomerErasure): the files must only go once THAT transaction has
     * committed — a file cannot be rolled back.
     *
     * @return list<string>|null the archived files to delete afterwards; null
     *                           when the order does not exist or was done before
     */
    public function anonymizeRows(int $orderId, int $now): array|null
    {
        return $this->connection->transactional(function () use ($orderId, $now): array|null {
            $anonymizedAt = $this->connection->fetchOne('SELECT anonymizedAt FROM tl_consho_order WHERE id = ?', [$orderId]);

            if (false === $anonymizedAt || null !== $anonymizedAt) {
                return null;
            }

            $this->connection->update(
                'tl_consho_order',
                array_fill_keys(self::ORDER_FIELDS, '') + ['member' => 0, 'anonymizedAt' => $now, 'tstamp' => $now],
                ['id' => $orderId],
            );

            $paths = [];

            foreach ($this->connection->fetchAllAssociative('SELECT id, payload, archivedPath FROM tl_consho_document WHERE pid = ?', [$orderId]) as $document) {
                $payload = json_decode((string) $document['payload'], true);
                $update = ['tstamp' => $now];

                if (\is_array($payload) && \is_array($payload['order'] ?? null)) {
                    foreach (self::ORDER_FIELDS as $field) {
                        if (\array_key_exists($field, $payload['order'])) {
                            $payload['order'][$field] = '';
                        }
                    }

                    // A delivery note (dev#2) keeps the tracking number,
                    // which the delivery itself loses below.
                    if (\is_array($payload['shipment'] ?? null) && \array_key_exists('trackingNumber', $payload['shipment'])) {
                        $payload['shipment']['trackingNumber'] = '';
                    }

                    $update['payload'] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }

                if ('' !== (string) $document['archivedPath']) {
                    $paths[] = (string) $document['archivedPath'];
                    $update += ['archiveState' => 'removed', 'archivedPath' => '', 'archivedHash' => ''];
                }

                $this->connection->update('tl_consho_document', $update, ['id' => $document['id']]);
            }

            $childIds = [];

            foreach (self::VERSIONED_CHILDREN as $table) {
                $childIds[$table] = array_map('intval', $this->connection->fetchFirstColumn("SELECT id FROM $table WHERE pid = ?", [$orderId]));
            }

            $this->connection->executeStatement("UPDATE tl_consho_withdrawal SET name = '', email = '', part = NULL, message = NULL, reason = NULL, tstamp = ? WHERE pid = ?", [$now, $orderId]);
            $this->connection->executeStatement('UPDATE tl_consho_complaint SET note = NULL, tstamp = ? WHERE pid = ?', [$now, $orderId]);
            $this->connection->executeStatement("UPDATE tl_consho_payment SET reference = '', tstamp = ? WHERE pid = ?", [$now, $orderId]);
            $this->connection->executeStatement("UPDATE tl_consho_delivery SET trackingNumber = '', tstamp = ? WHERE pid = ?", [$now, $orderId]);

            // The provider's transaction reference names the payer at the
            // provider (Fable review D-5); the attempt itself stays. Not the
            // tokenHash: a hash of a random value, unique and NOT NULL — an
            // empty string on two rows would break the index.
            $this->connection->executeStatement("UPDATE tl_consho_payment_attempt SET providerReference = '', tstamp = ? WHERE pid = ?", [$now, $orderId]);

            // Block H (H-5): the use of a code stays counted, but no longer
            // says by whom — so it no longer counts against that customer's
            // limit either, which is what forgetting them means.
            $this->connection->executeStatement("UPDATE tl_consho_cart_rule_redemption SET member = 0, email = '', tstamp = ? WHERE orderId = ?", [$now, $orderId]);

            $this->connection->delete('tl_version', ['fromTable' => 'tl_consho_order', 'pid' => $orderId]);

            foreach ($childIds as $table => $ids) {
                foreach ($ids as $id) {
                    $this->connection->delete('tl_version', ['fromTable' => $table, 'pid' => $id]);
                }
            }

            $this->connection->delete('tl_consho_order_token', ['pid' => $orderId]);

            $order = $this->connection->quoteIdentifier('order');

            foreach (array_map('intval', $this->connection->fetchFirstColumn("SELECT id FROM tl_consho_cart WHERE $order = ?", [$orderId])) as $cartId) {
                $this->connection->delete('tl_consho_cart_item', ['pid' => $cartId]);
                $this->connection->delete('tl_consho_cart', ['id' => $cartId]);
            }

            $this->anonymizeEntitlements($orderId, $now);
            $this->anonymizeSubscriptions($orderId, $now);

            return $paths;
        });
    }

    /**
     * The order's entitlements no longer name the account, and the download
     * log goes — a list of moments, no voucher (Abo-DSGVO, P86; K4 from F).
     * The key stays with its entitlement: the code is stock that was handed
     * out, and the entitlement it points to names nobody any more.
     */
    private function anonymizeEntitlements(int $orderId, int $now): void
    {
        $ids = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_entitlement WHERE pid = ?', [$orderId]));

        if ([] === $ids) {
            return;
        }

        $this->connection->executeStatement('UPDATE tl_consho_entitlement SET member = 0, tstamp = ? WHERE pid = ?', [$now, $orderId]);

        foreach ($ids as $id) {
            $this->connection->delete('tl_consho_download', ['pid' => $id]);
            $this->connection->delete('tl_version', ['fromTable' => 'tl_consho_entitlement', 'pid' => $id]);
        }
    }

    /**
     * A subscription goes once it has ended AND every order of it — the
     * purchase and each follow-up — is anonymised (Abo-DSGVO, P85). Asked
     * after each order, so the last of them closes it; within one erasure
     * request the earlier orders of the same transaction already count.
     *
     * Number, terms and amounts stay, like the order's: the terms text names
     * the contract, not the person. What goes is the link to the account,
     * what the cancellations hold about the person (name, address, the
     * customer's reason, the operator's reply) and the versions of both.
     */
    private function anonymizeSubscriptions(int $orderId, int $now): void
    {
        $ids = $this->connection->fetchFirstColumn(
            "SELECT s.id FROM tl_consho_subscription s
             WHERE (s.firstOrder = :order OR s.id = (SELECT o.subscription FROM tl_consho_order o WHERE o.id = :order))
               AND s.state = 'ended'
               AND NOT EXISTS (SELECT 1 FROM tl_consho_order r
                               WHERE (r.id = s.firstOrder OR r.subscription = s.id) AND r.anonymizedAt IS NULL)",
            ['order' => $orderId],
        );

        foreach (array_map('intval', $ids) as $id) {
            $this->anonymizeSubscription($id, $now);
        }
    }

    /**
     * The net under the rule above (Fable review Abo-DSGVO, S2): a contract
     * can END after its last order was anonymised — paid in advance for two
     * years in a shop that keeps one, or an erasure request whose orders ran
     * out of their period first. No order is anonymised any more then, so
     * nothing would ask. The daily RetentionCron asks here.
     *
     * @return int how many subscriptions were anonymised
     */
    public function anonymizeEndedSubscriptions(int $now): int
    {
        // member > 0 marks "not yet anonymised" only because every
        // subscription starts with an account (SubscriptionRecorder refuses
        // one without: SubscriptionWithoutAccount). Should that ever change,
        // the cancellations still name the person (second Fable review, 2).
        $ids = array_map('intval', $this->connection->fetchFirstColumn(
            "SELECT s.id FROM tl_consho_subscription s
             WHERE s.state = 'ended'
               AND (s.member > 0 OR EXISTS (SELECT 1 FROM tl_consho_cancellation c WHERE c.pid = s.id AND (c.name <> '' OR c.email <> '')))
               AND EXISTS (SELECT 1 FROM tl_consho_order f WHERE f.id = s.firstOrder)
               AND NOT EXISTS (SELECT 1 FROM tl_consho_order r
                               WHERE (r.id = s.firstOrder OR r.subscription = s.id) AND r.anonymizedAt IS NULL)
             ORDER BY s.id",
        ));

        foreach ($ids as $id) {
            $this->connection->transactional(fn () => $this->anonymizeSubscription($id, $now));
        }

        return \count($ids);
    }

    /**
     * Withdrawals that never found their order (WA, A6): no order, so no
     * order's period to follow. Kept three years (Michael, 2026-10-22), from
     * the end of the year of receipt like the regular limitation period
     * (§ 195, § 199 Abs. 1 BGB), as proof of what was declared and when, then
     * emptied like an anonymised order's.
     *
     * Number, receipt, shop and state stay: the number range stays unbroken,
     * and the record no longer names anybody.
     *
     * @return int how many withdrawals were anonymised
     */
    public function anonymizeUnassignedWithdrawals(int $now): int
    {
        $ids = [];

        foreach ($this->connection->fetchAllAssociative(
            "SELECT id, receivedAt FROM tl_consho_withdrawal
              WHERE pid = 0 AND (name <> '' OR email <> '' OR orderReference <> '' OR part IS NOT NULL OR message IS NOT NULL OR reason IS NOT NULL)
              ORDER BY id",
        ) as $row) {
            if (RetentionPolicy::endOf((int) $row['receivedAt'], self::UNASSIGNED_WITHDRAWAL_YEARS) < $now) {
                $ids[] = (int) $row['id'];
            }
        }

        foreach ($ids as $id) {
            $this->connection->transactional(function () use ($id, $now): void {
                $this->connection->executeStatement(
                    "UPDATE tl_consho_withdrawal SET name = '', email = '', orderReference = '', part = NULL, message = NULL, reason = NULL, tstamp = ? WHERE id = ? AND pid = 0",
                    [$now, $id],
                );
                $this->connection->delete('tl_version', ['fromTable' => 'tl_consho_withdrawal', 'pid' => $id]);
            });
        }

        return \count($ids);
    }

    private function anonymizeSubscription(int $id, int $now): void
    {
        // The mandate with it (Abo-4b, P98): what a provider charges the
        // person with is data about them, and an ended contract charges nothing.
        $this->connection->update('tl_consho_subscription', ['member' => 0, 'mandate' => '', 'mandateMethod' => 0, 'tstamp' => $now], ['id' => $id]);

        foreach (array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_cancellation WHERE pid = ?', [$id])) as $cancellation) {
            $this->connection->delete('tl_version', ['fromTable' => 'tl_consho_cancellation', 'pid' => $cancellation]);
        }

        $this->connection->executeStatement(
            "UPDATE tl_consho_cancellation SET name = '', email = '', reason = NULL, rejectReason = NULL, tstamp = ? WHERE pid = ?",
            [$now, $id],
        );
        $this->connection->delete('tl_version', ['fromTable' => 'tl_consho_subscription', 'pid' => $id]);
    }

    /**
     * Deletes archived documents of anonymised orders — only ever after the
     * transaction that emptied their rows has committed.
     *
     * @param list<string> $paths relative to the upload path, as stored
     */
    public function deleteFiles(array $paths): void
    {
        foreach ($paths as $path) {
            try {
                if ($this->files->fileExists($path)) {
                    $this->files->delete($path);
                }
            } catch (\Throwable $e) {
                // The database no longer points at the file; say where it is.
                $this->logger->error(\sprintf('The archived document "%s" of an anonymised order could not be deleted: %s', $path, $e->getMessage()), ['exception' => $e]);
            }
        }
    }
}
