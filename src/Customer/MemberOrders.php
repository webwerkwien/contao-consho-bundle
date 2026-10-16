<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Doctrine\DBAL\Connection;

/**
 * The orders a signed-in member may see in "Meine Bestellungen" (D-4, K3).
 *
 *  - Always the orders placed under the account, in the shop of the page.
 *  - The guest orders under the account's address only once that address is
 *    CONFIRMED (Michael, 2026-09-28: „ok, mit Bestätigungsmail bauen").
 *    Contao's "Persönliche Daten" changes tl_member.email without any check;
 *    unconfirmed, an account could take on somebody else's address and read
 *    their guest orders — address, articles, documents — without a single
 *    mail going anywhere.
 *
 * The confirmation is stored as the address it confirmed
 * (`consho_confirmedEmail`), not as a flag: when the member changes the
 * address, the stored one no longer matches and the guest orders disappear
 * until the new one is confirmed. A flag would have kept vouching for an
 * address the account no longer has.
 *
 * Guest orders are orders without an EXISTING account, as in the customer
 * list — an order of another account never shows, whatever its address.
 * owns() is list()'s condition for one order, so a link can never open what
 * the list would not show.
 */
class MemberOrders
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{id: int, orderNumber: string, createdAt: int, grandTotal: string, currency: string, guest: bool}>
     */
    public function list(int $shopId, int $memberId): array
    {
        if ($memberId < 1) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.id, o.orderNumber, o.createdAt, o.grandTotal, o.currency, o.member
             FROM tl_consho_order o
             LEFT JOIN tl_member a ON a.id = o.member AND o.member > 0
             WHERE o.shop = :shop AND ('.$this->condition().')
             ORDER BY o.createdAt DESC, o.id DESC',
            ['shop' => $shopId, 'member' => $memberId, 'mail' => $this->confirmedAddress($memberId) ?? ''],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'orderNumber' => (string) $row['orderNumber'],
            'createdAt' => (int) $row['createdAt'],
            'grandTotal' => (string) $row['grandTotal'],
            'currency' => (string) $row['currency'],
            'guest' => (int) $row['member'] !== $memberId,
        ], $rows);
    }

    public function owns(int $shopId, int $memberId, int $orderId): bool
    {
        if ($memberId < 1 || $orderId < 1) {
            return false;
        }

        return false !== $this->connection->fetchOne(
            'SELECT o.id
             FROM tl_consho_order o
             LEFT JOIN tl_member a ON a.id = o.member AND o.member > 0
             WHERE o.id = :order AND o.shop = :shop AND ('.$this->condition().')',
            ['order' => $orderId, 'shop' => $shopId, 'member' => $memberId, 'mail' => $this->confirmedAddress($memberId) ?? ''],
        );
    }

    /**
     * Whether the account's CURRENT address is the confirmed one.
     */
    public function guestOrdersConfirmed(int $memberId): bool
    {
        return null !== $this->confirmedAddress($memberId);
    }

    /**
     * Called after EmailConfirmation::confirm() — by the caller, which has
     * checked that the confirming browser is signed in as this member.
     */
    public function markConfirmed(int $memberId, string $email, int $now): void
    {
        $this->connection->update('tl_member', [
            'consho_confirmedEmail' => mb_strtolower(trim($email)),
            'consho_confirmedEmailAt' => $now,
            'tstamp' => $now,
        ], ['id' => $memberId]);
    }

    /**
     * The confirmed address in lower case, or null while the account's
     * current address is not the one confirmed.
     */
    private function confirmedAddress(int $memberId): string|null
    {
        $row = $this->connection->fetchAssociative('SELECT email, consho_confirmedEmail FROM tl_member WHERE id = ?', [$memberId]);

        if (false === $row) {
            return null;
        }

        $current = mb_strtolower(trim((string) $row['email']));

        return '' !== $current && $current === (string) $row['consho_confirmedEmail'] ? $current : null;
    }

    /**
     * The account's own orders, or — with a confirmed address — a guest
     * order under it. ':mail' is '' when nothing is confirmed, and no order
     * matches an empty address.
     */
    private function condition(): string
    {
        return "(o.member = :member AND a.id IS NOT NULL) OR (:mail <> '' AND a.id IS NULL AND LOWER(o.email) = :mail)";
    }
}
