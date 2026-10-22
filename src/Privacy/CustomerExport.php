<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Privacy;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerDirectory;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherCode;

/**
 * The copy of a customer's data for an access request (D-5, L1; Art. 15 Abs.
 * 3 DSGVO): a ZIP with `daten.json` and the PDFs of the documents.
 *
 * JSON because Art. 15 Abs. 3 asks for "einem gängigen elektronischen
 * Format" when the request came electronically, and the same file serves
 * portability (Art. 20: structured, common, machine-readable). The PDFs
 * because a customer reads an invoice, not a payload.
 *
 * What is in it is what Consho keeps about the person — the lines of "Kunden"
 * (CustomerDirectory): orders with lines, payments, deliveries, withdrawals,
 * complaints, documents and entitlements (downloads, keys), the subscriptions
 * bought with them and their cancellations, withdrawals through the form that
 * matched no order (by the address typed, WA), and for a member the account's
 * address fields.
 * Internal ids stay out; they mean nothing outside the shop. Passwords and
 * login data of the account are Contao's and never leave it.
 *
 * Consho sends nothing: the operator checks the identity (Art. 12 Abs. 6)
 * and adds the information of Art. 15 Abs. 1 a–h — Consho sets no legal text.
 */
class CustomerExport
{
    private const ORDER_FIELDS = [
        'orderNumber', 'createdAt', 'language', 'currency', 'netTotal', 'taxTotal', 'grandTotal',
        'salutation', 'firstname', 'lastname', 'company', 'email', 'phone',
        'billingStreet', 'billingPostal', 'billingCity', 'billingState', 'billingCountry',
        'shippingCompany', 'shippingFirstname', 'shippingLastname', 'shippingStreet', 'shippingPostal', 'shippingCity', 'shippingState', 'shippingCountry', 'shippingPhone',
        'dateOfBirth', 'vatId', 'vatIdStatus', 'vatIdRequestIdentifier', 'shippingMethodTitle', 'paymentMethodTitle',
        'termsAcceptedAt', 'digitalConsentAt', 'emailConfirmedAt', 'erasureRequestedAt', 'anonymizedAt',
    ];

    /** Contao's address fields of an account, and Consho's own. */
    private const MEMBER_FIELDS = [
        'firstname', 'lastname', 'dateOfBirth', 'gender', 'company', 'street', 'postal', 'city', 'state', 'country',
        'phone', 'mobile', 'fax', 'email', 'website', 'language', 'username', 'dateAdded',
        'consho_shippingCompany', 'consho_shippingSalutation', 'consho_shippingFirstname', 'consho_shippingLastname',
        'consho_shippingStreet', 'consho_shippingPostal', 'consho_shippingCity', 'consho_shippingState', 'consho_shippingCountry',
        'consho_vatId', 'consho_vatIdStatus', 'consho_vatIdCheckedAt', 'consho_vatIdRequestIdentifier',
        'consho_confirmedEmail', 'consho_confirmedEmailAt',
    ];

    /** Fields holding a timestamp — written as ISO 8601 for a human reader. */
    private const MOMENTS = [
        'createdAt', 'termsAcceptedAt', 'digitalConsentAt', 'emailConfirmedAt', 'erasureRequestedAt', 'anonymizedAt', 'receivedAt', 'shippedAt', 'reportedAt',
        'issuedAt', 'decidedAt', 'returnReceivedAt', 'refundedAt', 'resolvedAt', 'confirmationSentAt', 'decisionSentAt', 'dateAdded', 'consho_vatIdCheckedAt', 'consho_confirmedEmailAt',
        'validFrom', 'validUntil', 'suspendedAt', 'assignedAt',
        'startedAt', 'minimumEndsAt', 'periodFrom', 'periodUntil', 'nextBillingAt', 'endsAt', 'reminderSentAt', 'requestedAt', 'effectiveAt', 'earliestAt',
        'pauseFrom', 'pauseUntil', 'withdrawnAt',
    ];

    /** What a subscription says about the contract (Abo-DSGVO, P84). */
    private const SUBSCRIPTION_FIELDS = [
        'subscriptionNumber', 'ruleTitle', 'termLength', 'termUnit', 'billingInterval', 'renewal', 'noticeDays',
        'quantity', 'unitNet', 'currency', 'kind', 'terms', 'phase', 'state',
        'startedAt', 'minimumEndsAt', 'periodFrom', 'periodUntil', 'nextBillingAt', 'endsAt', 'reminderSentAt',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CustomerDirectory $directory,
        private readonly DocumentPdfInterface $pdfs,
    ) {
    }

    /**
     * @return array{fileName: string, zip: string}|null null: no such customer
     */
    public function export(int $shopId, string $key, int $now): array|null
    {
        $customer = $this->directory->find($shopId, $key);

        if (null === $customer) {
            return null;
        }

        $files = [];
        $orders = [];

        foreach ($this->directory->orders($shopId, $key) as $listed) {
            $id = $listed['id'];
            $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]) ?: [];
            $documents = [];

            foreach ($this->connection->fetchAllAssociative('SELECT id, type, documentNumber, issuedAt FROM tl_consho_document WHERE pid = ? ORDER BY issuedAt, id', [$id]) as $document) {
                $pdf = $this->pdfs->pdf((int) $document['id']);
                $file = null;

                if (null !== $pdf) {
                    $file = 'belege/'.$pdf['fileName'];
                    $files[$file] = $pdf['pdf'];
                }

                $documents[] = $this->clean(['type' => $document['type'], 'documentNumber' => $document['documentNumber'], 'issuedAt' => $document['issuedAt'], 'file' => $file]);
            }

            $orders[] = $this->pick($order, self::ORDER_FIELDS) + [
                'items' => $this->rows('SELECT productName, sku, lineType, quantity, unitPrice, taxRate, lineTotal FROM tl_consho_order_item WHERE pid = ? ORDER BY id', $id),
                'payments' => $this->rows('SELECT receivedAt, amount, method, reference FROM tl_consho_payment WHERE pid = ? ORDER BY receivedAt, id', $id),
                'deliveries' => $this->rows('SELECT shippedAt, carrier, trackingNumber, reportedAt FROM tl_consho_delivery WHERE pid = ? ORDER BY id', $id),
                'withdrawals' => $this->rows('SELECT number, receivedAt, source, name, email, orderReference, scope, part, message, state, decidedAt, reason, returnReceivedAt, refundAmount, refundedAt FROM tl_consho_withdrawal WHERE pid = ? ORDER BY id', $id),
                'complaints' => $this->rows('SELECT receivedAt, note, resolvedAt FROM tl_consho_complaint WHERE pid = ? ORDER BY id', $id),
                'paymentAttempts' => $this->rows('SELECT methodType, state, amount, currency, providerReference, createdAt FROM tl_consho_payment_attempt WHERE pid = ? ORDER BY id', $id),
                // Block H (H-5): the codes redeemed with the order, and whether a cancellation gave them back.
                // WG-6: the vouchers bought with the order — named by the last
                // group, like on the invoice; the code itself is money.
                'vouchers' => array_map(
                    static fn (array $row): array => ['code' => VoucherCode::masked((string) $row['code'])] + array_diff_key($row, ['code' => true]),
                    $this->rows("SELECT code, currency, faceValue, balance, validUntil, blocked, createdAt FROM tl_consho_voucher WHERE orderId = ? AND source = 'purchase' ORDER BY id", $id),
                ),
                'discountCodes' => $this->rows('SELECT r.code, c.title, r.cancelled FROM tl_consho_cart_rule_redemption r LEFT JOIN tl_consho_cart_rule c ON c.id = r.rule WHERE r.orderId = ? ORDER BY r.id', $id),
                'entitlements' => $this->entitlements($id),
                'documents' => $documents,
            ];
        }

        $account = null;

        if (null !== $customer->memberId) {
            $member = $this->connection->fetchAssociative('SELECT * FROM tl_member WHERE id = ?', [$customer->memberId]);
            $account = false === $member ? null : $this->pick($member, self::MEMBER_FIELDS);
        }

        $email = mb_strtolower($customer->email);

        $data = [
            'exportedAt' => date(\DATE_ATOM, $now),
            'shop' => $customer->shopTitle,
            'customer' => ['kind' => null === $customer->memberId ? 'guest' : 'member', 'name' => $customer->name, 'email' => $customer->email],
            'account' => $account,
            'orders' => $orders,
            // WA, A6: declarations through the form that matched no order —
            // they name the person by the address they typed, in this shop.
            'unassignedWithdrawals' => '' === $email ? [] : array_map(
                fn (array $row): array => $this->clean($row),
                $this->connection->fetchAllAssociative(
                    'SELECT number, receivedAt, source, name, email, orderReference, scope, part, message, state FROM tl_consho_withdrawal WHERE pid = 0 AND shop = ? AND LOWER(email) = ? ORDER BY id',
                    [$shopId, $email],
                ),
            ),
            'subscriptions' => $this->subscriptions(array_column($this->directory->orders($shopId, $key), 'id')),
            // Fable review D-5: what else Consho keeps about the person.
            'baskets' => $this->baskets($customer->memberId, $email),
            // Block G (G12): the wishes to hear when an article is back.
            'stockNotices' => null === $customer->memberId ? [] : array_map(
                fn (array $row): array => $this->clean($row),
                $this->connection->fetchAllAssociative(
                    'SELECT p.title AS product, n.createdAt FROM tl_consho_stock_notice n LEFT JOIN tl_consho_product p ON p.id = n.article WHERE n.member = ? ORDER BY n.id',
                    [$customer->memberId],
                ),
            ),
            'pendingConfirmations' => '' === $email ? [] : array_map(
                fn (array $row): array => $this->clean($row),
                $this->connection->fetchAllAssociative('SELECT email, createdOn, confirmedOn FROM tl_opt_in WHERE LOWER(email) = ? ORDER BY id', [$email]),
            ),
        ];

        return [
            'fileName' => \sprintf('auskunft-%s.zip', date('Y-m-d', $now)),
            'zip' => $this->zip(['daten.json' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)] + $files),
        ];
    }

    /**
     * What the purchase of digital goods and services left (Abo-DSGVO, P84;
     * K4 from F): validity, the moments of each download, and the keys in
     * plain text — the customer was given them, they are theirs to see.
     *
     * @return list<array<string, mixed>>
     */
    private function entitlements(int $orderId): array
    {
        $entitlements = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT e.id, i.productName, e.quantity, e.deliveryType, e.validFrom, e.validUntil, e.revoked, e.suspendedAt
             FROM tl_consho_entitlement e
             LEFT JOIN tl_consho_order_item i ON i.id = e.orderItem
             WHERE e.pid = ? ORDER BY e.id',
            [$orderId],
        ) as $row) {
            $id = (int) $row['id'];
            unset($row['id']);

            $entitlements[] = $this->clean($row) + [
                'downloads' => array_map(
                    static fn (mixed $at): string => date(\DATE_ATOM, (int) $at),
                    $this->connection->fetchFirstColumn('SELECT downloadedAt FROM tl_consho_download WHERE pid = ? ORDER BY downloadedAt, id', [$id]),
                ),
                'keys' => array_map(
                    fn (array $key): array => $this->clean($key),
                    $this->connection->fetchAllAssociative('SELECT code, assignedAt FROM tl_consho_key WHERE entitlement = ? ORDER BY id', [$id]),
                ),
            ];
        }

        return $entitlements;
    }

    /**
     * The subscriptions bought with these orders, each with its cancellations
     * (Abo-DSGVO, P84). Reached through the purchase order, like everything
     * else here: what the list of "Kunden" does not show is not exported.
     *
     * A mandate only as the method it charges through (Abo-4b, P98):
     * `followUpPayment`. The value is the provider's key to the person's
     * account, and a copy of it in a ZIP file is one more place to leak it.
     *
     * @param list<int> $orderIds
     *
     * @return list<array<string, mixed>>
     */
    private function subscriptions(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        $subscriptions = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT s.*, o.orderNumber AS purchaseOrder, m.title AS followUpPayment
               FROM tl_consho_subscription s
               JOIN tl_consho_order o ON o.id = s.firstOrder
               LEFT JOIN tl_consho_payment_method m ON m.id = s.mandateMethod
              WHERE s.firstOrder IN (?) ORDER BY s.id',
            [$orderIds],
            [ArrayParameterType::INTEGER],
        ) as $row) {
            if ('' === (string) $row['mandate']) {
                $row['followUpPayment'] = null;
            }

            $subscriptions[] = $this->pick($row, [...self::SUBSCRIPTION_FIELDS, 'purchaseOrder', 'followUpPayment']) + [
                'cancellations' => $this->rows('SELECT number, receivedAt, source, kind, reason, name, email, requestedAt, effectiveAt, earliestAt, state, decidedAt, decisionSentAt, rejectReason, confirmationSentAt FROM tl_consho_cancellation WHERE pid = ? ORDER BY id', (int) $row['id']),
                // Abo pausieren (P111): every pause, withdrawn ones included.
                'pauses' => $this->rows('SELECT months, pauseFrom, pauseUntil, source, requestedAt, withdrawnAt FROM tl_consho_subscription_pause WHERE pid = ? ORDER BY id', (int) $row['id']),
            ];
        }

        return $subscriptions;
    }

    /**
     * Baskets not yet cleaned up, with what the checkout holds (addresses,
     * date of birth) — the same match as the erasure (CustomerErasure).
     *
     * @return list<array<string, mixed>>
     */
    private function baskets(int|null $memberId, string $email): array
    {
        $rows = null === $memberId ? [] : $this->connection->fetchAllAssociative('SELECT id, state, checkoutData, updatedAt FROM tl_consho_cart WHERE member = ?', [$memberId]);

        if ('' !== $email) {
            $rows = array_merge($rows, $this->connection->fetchAllAssociative("SELECT id, state, checkoutData, updatedAt FROM tl_consho_cart WHERE LOWER(checkoutData) LIKE ? ESCAPE '!'", [CustomerErasure::basketPattern($email)]));
        }

        $baskets = [];

        foreach ($rows as $row) {
            $baskets[(int) $row['id']] = [
                'state' => (string) $row['state'],
                'updatedAt' => date(\DATE_ATOM, (int) $row['updatedAt']),
                'checkout' => json_decode((string) $row['checkoutData'], true),
            ];
        }

        return array_values($baskets);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, int $orderId): array
    {
        return array_map(fn (array $row): array => $this->clean($row), $this->connection->fetchAllAssociative($sql, [$orderId]));
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string>         $fields
     *
     * @return array<string, mixed>
     */
    private function pick(array $row, array $fields): array
    {
        return $this->clean(array_intersect_key($row, array_flip($fields)));
    }

    /**
     * Timestamps as dates, empty values left out — a field the customer never
     * filled in is no information about them.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function clean(array $row): array
    {
        $clean = [];

        foreach ($row as $field => $value) {
            if (null === $value || '' === $value) {
                continue;
            }

            if (\in_array($field, self::MOMENTS, true) && is_numeric($value)) {
                // 0 is "never" — no moment, so no information either
                // (acceptance Abo-DSGVO: "reminderSentAt": 0).
                if ((int) $value > 0) {
                    $clean[$field] = date(\DATE_ATOM, (int) $value);
                }

                continue;
            }

            // Contao keeps input encoded ("&#40;Datei-Abo&#41;"); the copy is
            // read as data, not as HTML (acceptance Abo-DSGVO).
            $clean[$field] = \is_string($value) ? html_entity_decode($value, \ENT_QUOTES | \ENT_HTML5, 'UTF-8') : $value;
        }

        return $clean;
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'consho-export');

        if (false === $path) {
            throw new \RuntimeException('No temporary file for the export.');
        }

        try {
            $zip = new \ZipArchive();

            if (true !== $zip->open($path, \ZipArchive::OVERWRITE)) {
                throw new \RuntimeException('The export archive could not be created.');
            }

            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
            }

            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }
}
