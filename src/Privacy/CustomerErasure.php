<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Privacy;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerDirectory;

/**
 * An erasure request for one customer of one shop (D-5, L3, L7).
 *
 * The customer is a line of "Kunden" (CustomerDirectory): a member with the
 * guest orders under the account's address, or a guest by address. Their
 * orders are what the list shows — nothing else is reached, and nothing the
 * list would not show.
 *
 *  - Orders past their period: anonymised now (OrderAnonymizer).
 *  - Orders within it: marked `erasureRequestedAt` and anonymised when the
 *    period ends, by the daily cron — shop setting or not. Until then they
 *    stay as they are: the document is the accounting voucher, kept by a
 *    legal duty (Art. 17 Abs. 3 lit. b DSGVO; the duties: § 132 BAO, § 212
 *    UGB, § 147 AO, § 257 HGB). Not § 35 Abs. 3 BDSG — that one is about
 *    retention periods set by statute of association or contract (D-5).
 *  - Without a period in the shop: marked, nothing anonymised — the finding
 *    on the start page asks for the period.
 *  - Now, whatever the period: baskets, pending confirmations (tl_opt_in),
 *    the Consho fields of the account, complaint notes. None of them is a
 *    voucher.
 *  - A running subscription does not stop the request (Abo-DSGVO, P87): the
 *    contract itself is the ground for keeping its data (Art. 6 Abs. 1 lit.
 *    b DSGVO), and ending it is the operator's call. It is named in the
 *    answer and goes with its last order once it has ended
 *    (OrderAnonymizer).
 *
 * The account itself is Contao's: the operator deletes the member there.
 */
class CustomerErasure
{
    /** The Consho fields of an account (contao/dca/tl_member.php). */
    public const MEMBER_FIELDS = [
        'consho_shippingCompany', 'consho_shippingSalutation', 'consho_shippingFirstname', 'consho_shippingLastname',
        'consho_shippingStreet', 'consho_shippingPostal', 'consho_shippingCity', 'consho_shippingState', 'consho_shippingCountry',
        'consho_vatId', 'consho_vatIdStatus', 'consho_vatIdRequestIdentifier', 'consho_confirmedEmail',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CustomerDirectory $directory,
        private readonly RetentionPolicy $policy,
        private readonly OrderAnonymizer $anonymizer,
    ) {
    }

    /**
     * @return array{anonymized: list<string>, pending: list<array{number: string, end: int|null}>, running: list<string>}|null null: no such customer
     */
    public function erase(int $shopId, string $key, int $now): array|null
    {
        $customer = $this->directory->find($shopId, $key);

        if (null === $customer) {
            return null;
        }

        $orders = $this->directory->orders($shopId, $key);
        $anonymized = [];
        $pending = [];
        $files = [];

        /*
         * One transaction for the whole request (D-5: a failure in
         * the last step left the first ones written). The archived files go
         * only after it has committed — a file cannot be rolled back.
         */
        $this->connection->beginTransaction();

        try {
            $this->eraseRows($orders, $customer->memberId, mb_strtolower($customer->email), $now, $anonymized, $pending, $files);
            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        $this->anonymizer->deleteFiles($files);

        return ['anonymized' => $anonymized, 'pending' => $pending, 'running' => $this->running(array_column($orders, 'id'))];
    }

    /**
     * Subscriptions bought with these orders that have not ended — their
     * numbers, for the answer (P87).
     *
     * @param list<int> $orderIds
     *
     * @return list<string>
     */
    private function running(array $orderIds): array
    {
        if ([] === $orderIds) {
            return [];
        }

        return array_map('strval', $this->connection->fetchFirstColumn(
            "SELECT subscriptionNumber FROM tl_consho_subscription WHERE firstOrder IN (?) AND state <> 'ended' ORDER BY id",
            [$orderIds],
            [ArrayParameterType::INTEGER],
        ));
    }

    /**
     * @param list<array{id: int, orderNumber: string}>             $orders
     * @param list<string>                                         $anonymized
     * @param list<array{number: string, end: int|null}>           $pending
     * @param list<string>                                         $files
     */
    private function eraseRows(array $orders, int|null $memberId, string $email, int $now, array &$anonymized, array &$pending, array &$files): void
    {
        foreach ($orders as $order) {
            $end = $this->policy->endOfOrder($order['id']);

            if (null !== $end && $end < $now) {
                array_push($files, ...($this->anonymizer->anonymizeRows($order['id'], $now) ?? []));
                $anonymized[] = $order['orderNumber'];

                continue;
            }

            $this->connection->executeStatement(
                'UPDATE tl_consho_order SET erasureRequestedAt = ?, tstamp = ? WHERE id = ? AND erasureRequestedAt IS NULL',
                [$now, $now, $order['id']],
            );
            $pending[] = ['number' => $order['orderNumber'], 'end' => $end];
        }

        $orderIds = array_column($orders, 'id');

        if ([] !== $orderIds) {
            $this->connection->executeStatement(
                'UPDATE tl_consho_complaint SET note = NULL, tstamp = ? WHERE pid IN (?)',
                [$now, $orderIds],
                [ParameterType::INTEGER, ArrayParameterType::INTEGER],
            );
        }

        $this->eraseAround($memberId, $email, $now);
    }

    /**
     * The LIKE pattern finding a basket by the address in its checkout JSON,
     * for `LOWER(checkoutData) LIKE ? ESCAPE '!'` — one place, so the access
     * copy (CustomerExport) and the erasure find the same baskets.
     */
    public static function basketPattern(string $email): string
    {
        return '%'.strtr(substr(json_encode(['email' => mb_strtolower($email)]) ?: '', 1, -1), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }

    /**
     * What is no voucher and goes now: baskets, pending confirmations, stock
     * notices, the account's Consho fields.
     */
    private function eraseAround(int|null $memberId, string $email, int $now): void
    {
        $carts = [];

        if (null !== $memberId) {
            $carts = array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_cart WHERE member = ?', [$memberId]));

            $this->connection->update(
                'tl_member',
                array_fill_keys(self::MEMBER_FIELDS, '') + ['consho_vatIdCheckedAt' => null, 'consho_confirmedEmailAt' => null, 'tstamp' => $now],
                ['id' => $memberId],
            );

            // Contao's versions of the account keep the whole row, the Consho
            // fields included (D-5). Contao does not remove them
            // when the member is deleted either.
            $this->connection->delete('tl_version', ['fromTable' => 'tl_member', 'pid' => $memberId]);

            // "Benachrichtigen, wenn wieder da" (Block G, G12): a wish is no
            // voucher, it goes.
            $this->connection->delete('tl_consho_stock_notice', ['member' => $memberId]);
        }

        if ('' !== $email) {
            // A guest's basket has its address only inside the JSON. Encoded
            // with the flags CheckoutData::toJson() uses (none), so an umlaut
            // or a slash is escaped here exactly as it is there.
            //
            // 🔴 The LIKE escape is `!`, not a backslash: `ESCAPE '\'` is fine
            // in SQLite and a syntax error in MariaDB, where the backslash
            // escapes the closing quote (D-5, a 500 that no
            // test could see).
            foreach ($this->connection->fetchFirstColumn("SELECT id FROM tl_consho_cart WHERE LOWER(checkoutData) LIKE ? ESCAPE '!'", [self::basketPattern($email)]) as $id) {
                $carts[] = (int) $id;
            }

            foreach ($this->connection->fetchFirstColumn('SELECT id FROM tl_opt_in WHERE LOWER(email) = ?', [$email]) as $optIn) {
                $this->connection->delete('tl_opt_in_related', ['pid' => (int) $optIn]);
                $this->connection->delete('tl_opt_in', ['id' => (int) $optIn]);
            }
        }

        foreach (array_unique($carts) as $cartId) {
            $this->connection->delete('tl_consho_cart_item', ['pid' => $cartId]);
            $this->connection->delete('tl_consho_cart', ['id' => $cartId]);
        }
    }
}
