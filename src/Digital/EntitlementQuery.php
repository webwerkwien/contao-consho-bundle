<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;

/**
 * Whether a customer may use something now — the extension point for modules
 * (Abo-5, P40), and the one place the order page asks too.
 *
 * Until Abo-5 a module learned about its entitlement only at the release
 * (DeliveryHandlerInterface::release) and had no way to ask again. A licence
 * server, a members' area or an update check asks here: an entitlement counts
 * when Entitlement::isActive() says so — not revoked, not resting for an
 * unpaid invoice (P33), not past its end — AND its order line has not ended
 * (a cancelled order, a withdrawal accepted in full).
 *
 * Read-only. What an inactive entitlement stops — updates, use, a download —
 * is the product's business (F1); the core only answers.
 *
 * Not final: consumers' tests stand a double in for it.
 */
class EntitlementQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function find(int $entitlementId): Entitlement|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_entitlement WHERE id = ?', [$entitlementId]);

        return false === $row ? null : Entitlement::fromRow($row);
    }

    public function isActive(int $entitlementId, int $now): bool
    {
        $entitlement = $this->find($entitlementId);

        return null !== $entitlement && $this->usable($entitlement, $now);
    }

    /**
     * The entitlements a member may use now for a product, oldest first — a
     * variant counts as its own product, as at the purchase.
     *
     * @return list<Entitlement>
     */
    public function activeFor(int $memberId, int $productId, int $now): array
    {
        // Not signed in is nobody — not every guest's entitlement.
        if ($memberId < 1) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT * FROM tl_consho_entitlement WHERE member = ? AND product = ? ORDER BY validFrom, id',
            [$memberId, $productId],
        );

        return array_values(array_filter(
            array_map(Entitlement::fromRow(...), $rows),
            fn (Entitlement $entitlement): bool => $this->usable($entitlement, $now),
        ));
    }

    public function usable(Entitlement $entitlement, int $now): bool
    {
        return $entitlement->isActive($now) && !isset($this->endedLines($entitlement->orderId)[$entitlement->orderItemId]);
    }

    /**
     * Order lines whose contract has ended (F): the whole order when
     * it is cancelled, otherwise every line an ACCEPTED withdrawal covers in
     * full. Nothing else revokes an entitlement on its own — the operator's
     * "gesperrt" stays the explicit switch — but a use after the money went
     * back would be a delivery nobody pays for.
     *
     * @return array<int, true> order item id
     */
    public function endedLines(int $orderId): array
    {
        $cancelled = null !== ($this->connection->fetchOne('SELECT cancelledAt FROM tl_consho_order WHERE id = ?', [$orderId]) ?: null);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT i.id, i.quantity, COALESCE(SUM(CASE WHEN w.state = 'accepted' THEN wi.quantity ELSE 0 END), 0) AS withdrawn
               FROM tl_consho_order_item i
               LEFT JOIN tl_consho_withdrawal_item wi ON wi.orderItem = i.id
               LEFT JOIN tl_consho_withdrawal w ON w.id = wi.pid
              WHERE i.pid = ?
              GROUP BY i.id, i.quantity",
            [$orderId],
        );

        $ended = [];

        foreach ($rows as $row) {
            if ($cancelled || ((float) $row['withdrawn'] > 0 && (float) $row['withdrawn'] >= (float) $row['quantity'])) {
                $ended[(int) $row['id']] = true;
            }
        }

        return $ended;
    }
}
