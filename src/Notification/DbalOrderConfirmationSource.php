<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Doctrine\DBAL\Connection;

/**
 * Reads what the confirmation mails need from the frozen order (C-9).
 *
 * Everything customer-facing comes from rows written at the purchase — the
 * order, its lines, its legal texts, the invoice snapshot. Only the
 * delivery route is read from today's shop: who the copy goes to and which
 * mailbox sends it are questions about now, not about the sale.
 */
final class DbalOrderConfirmationSource implements OrderConfirmationSourceInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function order(int $orderId): array|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId]);

        return false === $row ? null : $row;
    }

    public function entitlements(int $orderId, int $since = 0): array
    {
        return array_map(
            static fn (array $row): array => ['quantity' => (string) $row['quantity'], 'name' => (string) ($row['productName'] ?? ''), 'service' => 'service' === (string) ($row['kind'] ?? '')],
            $this->connection->fetchAllAssociative(
                'SELECT e.quantity, i.productName, i.kind FROM tl_consho_entitlement e
                 LEFT JOIN tl_consho_order_item i ON i.id = e.orderItem
                 WHERE e.pid = ? AND e.validFrom >= ? AND e.revoked = 0 ORDER BY e.id',
                [$orderId, $since],
            ),
        );
    }

    public function items(int $orderId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_consho_order_item WHERE pid = ? ORDER BY sorting, id',
            [$orderId],
        );
    }

    public function legalTexts(int $orderId): array
    {
        /*
         * By id: OrderPlacer freezes them in LegalTextKind order, terms first,
         * which is the order a reader expects — what was agreed, then how to
         * undo it.
         */
        $rows = $this->connection->fetchAllAssociative(
            'SELECT headline, text FROM tl_consho_order_legal WHERE pid = ? ORDER BY id',
            [$orderId],
        );

        return array_map(
            static fn (array $row): array => ['headline' => (string) $row['headline'], 'text' => (string) ($row['text'] ?? '')],
            $rows,
        );
    }

    public function invoice(int $orderId): array|null
    {
        /*
         * Not cancelled: once the payment method can be changed (C-10), an
         * order carries a cancelled invoice and a new one, and the mail must
         * attach the one that stands.
         */
        $row = $this->connection->fetchAssociative(
            "SELECT d.id, d.documentNumber, d.payload FROM tl_consho_document d
             WHERE d.pid = ? AND d.type = 'invoice'
               AND NOT EXISTS (SELECT 1 FROM tl_consho_document c WHERE c.type = 'cancellation' AND c.relatedDocument = d.id)
             ORDER BY d.id DESC",
            [$orderId],
        );

        return false === $row ? null : $this->documentOf($row);
    }

    public function document(int $orderId, int $documentId): array|null
    {
        // Scoped to the order: a document id alone is not a way into
        // somebody else's order.
        $row = $this->connection->fetchAssociative(
            'SELECT id, documentNumber, payload FROM tl_consho_document WHERE id = ? AND pid = ?',
            [$documentId, $orderId],
        );

        return false === $row ? null : $this->documentOf($row);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{id: int, documentNumber: string, payload: array<string, mixed>}
     */
    private function documentOf(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? ''), true);

        return [
            'id' => (int) $row['id'],
            'documentNumber' => (string) $row['documentNumber'],
            'payload' => \is_array($payload) ? $payload : [],
        ];
    }

    public function shop(int $shopId): array|null
    {
        $shop = $this->connection->fetchAssociative(
            'SELECT operatorName, operatorAddress, fallbackLanguage, withdrawalDays, mailTransport FROM tl_consho_shop WHERE id = ?',
            [$shopId],
        );

        if (false === $shop) {
            return null;
        }

        $transport = (int) $shop['mailTransport'] > 0
            ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_mail_transport WHERE id = ?', [(int) $shop['mailTransport']])
            : false;

        return [
            // A mail header is no HTML: Contao stores the input encoded ("&amp;").
            'operatorName' => html_entity_decode((string) $shop['operatorName'], \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
            'operatorAddress' => (string) $shop['operatorAddress'],
            'fallbackLanguage' => (string) $shop['fallbackLanguage'],
            'withdrawalDays' => (int) $shop['withdrawalDays'],
            'transport' => false === $transport ? null : $transport,
        ];
    }

    public function markSent(int $orderId, int $now): void
    {
        $this->connection->update('tl_consho_order', ['confirmationSentAt' => $now, 'tstamp' => $now], ['id' => $orderId]);
    }

    public function rootOf(int $shopId, string $language): int|null
    {
        /*
         * A shop may have several start points — one per language, and since
         * C-1c one per currency. The language decides which order page and
         * which guarantee notice (per language) fit. Without a root in that
         * language, the fallback root, as Contao itself would serve; among
         * equals the lowest id wins, so the answer is the same on every call.
         *
         * A published root before an unpublished one, whatever its language:
         * links into an offline root lead nowhere. An unpublished root is
         * still better than none.
         *
         * `fallback` is a column; "rootfallback" is the palette of a root
         * with it set, not a type a row carries.
         */
        $id = $this->connection->fetchOne(
            "SELECT id FROM tl_page WHERE type IN ('root', 'rootfallback') AND consho_shop = ?
             ORDER BY CASE WHEN published = '1' THEN 0 ELSE 1 END,
                      CASE WHEN language = ? THEN 0 ELSE 1 END,
                      CASE WHEN fallback = '1' THEN 0 ELSE 1 END,
                      id",
            [$shopId, $language],
        );

        return false === $id ? null : (int) $id;
    }
}
