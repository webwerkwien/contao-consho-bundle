<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Who is a customer (D-4, K2) — computed, never stored.
 *
 * A customer follows from the orders. Members
 * serve Contao for much else (internal pages, say), so tl_member is not the
 * customer list; most orders may come from guests, and a list from
 * tl_member would miss them all. Three sources, per shop:
 *
 *  1. Members with at least one order in the shop.
 *  2. Guests, one per address — without regard to case, because the checkout
 *     keeps what was typed and "Erika@" and "erika@" are one mailbox.
 *  3. Members of the shop's base group without an order (accounts from the
 *     registration, K1).
 *
 * A guest with the address of a member of the SAME shop belongs to that
 * member (K2): the operator sees one person, not two. This is the back end
 * only — "Meine Bestellungen" shows guest orders only after the address is
 * confirmed (K3), because Contao lets a member change the address unchecked.
 *
 * An order whose member no longer exists counts as a guest order of its
 * address: the account is gone, the person is not.
 *
 * Rebuilt per request, from two grouped queries and one for names. Nothing
 * to maintain, correct back to the first order, and the price is a query the
 * database answers from its indexes.
 */
class CustomerDirectory
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param int|null $shopId null: every shop
     *
     * @return list<Customer> by last order, newest first; accounts without an order last
     */
    public function list(int|null $shopId, string $search = ''): array
    {
        $customers = [];

        foreach ($this->shops($shopId) as $shop) {
            array_push($customers, ...$this->ofShop((int) $shop['id'], (string) $shop['title'], (int) $shop['customerGroup']));
        }

        $search = mb_strtolower(trim($search));

        if ('' !== $search) {
            $customers = array_values(array_filter(
                $customers,
                static fn (Customer $c): bool => str_contains(mb_strtolower($c->name), $search) || str_contains(mb_strtolower($c->email), $search),
            ));
        }

        usort($customers, static fn (Customer $a, Customer $b): int => [null === $a->lastOrderAt, -($a->lastOrderAt ?? 0), $a->shopId, $a->key] <=> [null === $b->lastOrderAt, -($b->lastOrderAt ?? 0), $b->shopId, $b->key]);

        return $customers;
    }

    public function find(int $shopId, string $key): Customer|null
    {
        foreach ($this->list($shopId) as $customer) {
            if ($customer->key === $key) {
                return $customer;
            }
        }

        return null;
    }

    /**
     * The orders behind one line of the list, newest first.
     *
     * @return list<array{id: int, orderNumber: string, createdAt: int, grandTotal: string, currency: string, member: int, erasureRequestedAt: int|null}>
     */
    public function orders(int $shopId, string $key): array
    {
        $customer = $this->find($shopId, $key);

        if (null === $customer) {
            return [];
        }

        $mail = mb_strtolower($customer->email);

        // The member's own orders, and — merged, like the list (K2) — the
        // guest orders under the account's address.
        $rows = $this->connection->fetchAllAssociative(
            'SELECT o.id, o.orderNumber, o.createdAt, o.grandTotal, o.currency, o.member, o.erasureRequestedAt
             FROM tl_consho_order o
             LEFT JOIN tl_member m ON m.id = o.member AND o.member > 0
             WHERE o.shop = :shop
               AND ((:member > 0 AND o.member = :member AND m.id IS NOT NULL)
                 OR (m.id IS NULL AND LOWER(o.email) = :mail))
             ORDER BY o.createdAt DESC, o.id DESC',
            ['shop' => $shopId, 'member' => $customer->memberId ?? 0, 'mail' => $mail],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'orderNumber' => (string) $row['orderNumber'],
            'createdAt' => (int) $row['createdAt'],
            'grandTotal' => (string) $row['grandTotal'],
            'currency' => (string) $row['currency'],
            'member' => (int) $row['member'],
            'erasureRequestedAt' => null === $row['erasureRequestedAt'] ? null : (int) $row['erasureRequestedAt'],
        ], $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function shops(int|null $shopId = null): array
    {
        $shops = null === $shopId
            ? $this->connection->fetchAllAssociative('SELECT id, title, customerGroup FROM tl_consho_shop ORDER BY title, id')
            : $this->connection->fetchAllAssociative('SELECT id, title, customerGroup FROM tl_consho_shop WHERE id = ?', [$shopId]);

        // Stored encoded by Contao ("&#40;"); the list and the export escape themselves.
        return array_map(
            static fn (array $shop): array => ['title' => html_entity_decode((string) $shop['title'], \ENT_QUOTES | \ENT_HTML5, 'UTF-8')] + $shop,
            $shops,
        );
    }

    /**
     * @return list<Customer>
     */
    private function ofShop(int $shopId, string $title, int $groupId): array
    {
        /** @var array<string, array{memberId: int|null, name: string, email: string, orders: int, last: int|null, lastId: int, alsoGuest: bool}> $lines */
        $lines = [];

        // 1. Members with orders here.
        foreach ($this->connection->fetchAllAssociative(
            'SELECT m.id, m.firstname, m.lastname, m.email, COUNT(*) AS n, MAX(o.createdAt) AS last
             FROM tl_consho_order o
             JOIN tl_member m ON m.id = o.member
             WHERE o.shop = ? AND o.member > 0
             GROUP BY m.id, m.firstname, m.lastname, m.email',
            [$shopId],
        ) as $row) {
            $lines['m:'.$row['id']] = [
                'memberId' => (int) $row['id'],
                'name' => trim($row['firstname'].' '.$row['lastname']),
                'email' => (string) $row['email'],
                'orders' => (int) $row['n'],
                'last' => (int) $row['last'],
                'lastId' => 0,
                'alsoGuest' => false,
            ];
        }

        // 3. The base group, for accounts without an order. `groups` is a
        // serialized list; the LIKE narrows, the deserialize decides — "3"
        // must not match "13".
        if ($groupId > 0) {
            foreach ($this->connection->fetchAllAssociative(
                \sprintf('SELECT id, firstname, lastname, email, %s AS memberGroups FROM tl_member WHERE %1$s LIKE ?', $this->connection->quoteIdentifier('groups')),
                ['%"'.$groupId.'"%'],
            ) as $row) {
                if (isset($lines['m:'.$row['id']]) || !\in_array($groupId, array_map('intval', StringUtil::deserialize($row['memberGroups'], true)), true)) {
                    continue;
                }

                $lines['m:'.$row['id']] = [
                    'memberId' => (int) $row['id'],
                    'name' => trim($row['firstname'].' '.$row['lastname']),
                    'email' => (string) $row['email'],
                    'orders' => 0,
                    'last' => null,
                    'lastId' => 0,
                    'alsoGuest' => false,
                ];
            }
        }

        $memberByMail = [];

        foreach ($lines as $key => $line) {
            $memberByMail[mb_strtolower($line['email'])] = $key;
        }

        // 2. Guests — orders without an existing account — by address.
        foreach ($this->connection->fetchAllAssociative(
            "SELECT LOWER(o.email) AS mail, COUNT(*) AS n, MAX(o.createdAt) AS last, MAX(o.id) AS lastId
             FROM tl_consho_order o
             LEFT JOIN tl_member m ON m.id = o.member AND o.member > 0
             WHERE o.shop = ? AND m.id IS NULL AND o.email <> ''
             GROUP BY LOWER(o.email)",
            [$shopId],
        ) as $row) {
            $mail = (string) $row['mail'];
            $memberKey = $memberByMail[$mail] ?? null;

            if (null !== $memberKey) {
                $lines[$memberKey]['orders'] += (int) $row['n'];
                $lines[$memberKey]['last'] = max($lines[$memberKey]['last'] ?? 0, (int) $row['last']);
                $lines[$memberKey]['alsoGuest'] = true;

                continue;
            }

            $lines['g:'.$mail] = [
                'memberId' => null,
                'name' => '',
                'email' => $mail,
                'orders' => (int) $row['n'],
                'last' => (int) $row['last'],
                'lastId' => (int) $row['lastId'],
                'alsoGuest' => false,
            ];
        }

        // The guest's name as on the latest order — people marry, move, fix
        // a typo; the newest is the one they would recognise.
        $lastIds = array_filter(array_column($lines, 'lastId'));

        if ([] !== $lastIds) {
            foreach ($this->connection->fetchAllAssociative(
                'SELECT LOWER(email) AS mail, firstname, lastname, email FROM tl_consho_order WHERE id IN (?)',
                [array_values($lastIds)],
                [ArrayParameterType::INTEGER],
            ) as $row) {
                if (isset($lines['g:'.$row['mail']])) {
                    $lines['g:'.$row['mail']]['name'] = trim($row['firstname'].' '.$row['lastname']);
                    $lines['g:'.$row['mail']]['email'] = (string) $row['email'];
                }
            }
        }

        $customers = [];

        foreach ($lines as $key => $line) {
            $customers[] = new Customer((string) $key, $shopId, $title, $line['memberId'], $line['name'], $line['email'], $line['orders'], $line['last'], $line['alsoGuest']);
        }

        return $customers;
    }
}
