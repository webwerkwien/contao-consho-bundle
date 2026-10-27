<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Order\OrderLineType;

/**
 * What an order's withdrawals read: the goods that can still be named, and
 * the withdrawals already received. One place for both, so the form on the
 * order page offers exactly what the recorder accepts.
 */
class DbalWithdrawalRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * The goods lines — the only ones a parcel carries, and so the only ones a
     * withdrawal can name — with what is left after earlier withdrawals, and
     * whether the line goes only whole: a subscription's (Abo-8, P77) — a part
     * would change the quantity of a running contract, and there is no such
     * change (A11).
     *
     * @return array<int, array{name: string, quantity: string, left: string, whole: bool}>
     */
    public function goods(int $orderId): array
    {
        $withdrawn = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT wi.orderItem, SUM(wi.quantity) FROM tl_consho_withdrawal_item wi
             INNER JOIN tl_consho_withdrawal w ON w.id = wi.pid
             WHERE w.pid = ?
             GROUP BY wi.orderItem',
            [$orderId],
        ));

        /*
         * Released digital content (F-4, F17). A release happens only with
         * the consumer's consent, for a business customer, or once the period
         * is over (DigitalRelease) — in each case nothing is left to withdraw
         * from (§ 18 Abs. 1 Z 11 FAGG, § 356 Abs. 6 BGB).
         *
         * Not so for a service (Abo-8, P76): the consumer's consent to an
         * early start leaves the right standing and only owes the value of
         * what was rendered (§ 357a Abs. 2 BGB). Its release uses nothing up
         * — for a consumer. For a business customer it does, as for content
         * (F-4); the release of a service changed nothing there
         * (Abo-8).
         *
         * Until it is fully rendered (Dienstleistung, DL3): "Leistung
         * erbracht" (ServicePerformed) with the consumer's consent to the
         * early start ends the right (§ 356 Abs. 4 BGB, § 18 Abs. 1 Z 1
         * FAGG). Without the consent it does not — the right stands, and the
         * value of what was rendered is owed (ValueCompensation).
         */
        $order = $this->connection->fetchAssociative('SELECT businessCustomer, digitalConsentAt FROM tl_consho_order WHERE id = ?', [$orderId]);
        $business = (bool) ($order['businessCustomer'] ?? false);
        $consented = null !== ($order['digitalConsentAt'] ?? null);

        // The consent decided in PHP, not as a parameter: SQLite never
        // matched `1 = ?` against a bound value (Abo-8).
        $condition = match (true) {
            $business => '',
            $consented => ' AND (i.kind <> ? OR i.performedAt IS NOT NULL)',
            default => ' AND i.kind <> ?',
        };

        $released = array_map(strval(...), $this->connection->fetchAllKeyValue(
            'SELECT e.orderItem, SUM(e.quantity) FROM tl_consho_entitlement e
             INNER JOIN tl_consho_order_item i ON i.id = e.orderItem
             WHERE e.pid = ?'.$condition.'
             GROUP BY e.orderItem',
            $business ? [$orderId] : [$orderId, ProductKind::Service->value],
        ));

        /*
         * "Die ganze Bestellung" names no positions, so it uses up everything
         * (Abo-8: without this the order page went on offering the
         * withdrawal). Counted in every state, as positions are; a part in free
         * text names no quantity and uses up nothing.
         */
        $whole = false !== $this->connection->fetchOne("SELECT 1 FROM tl_consho_withdrawal WHERE pid = ? AND scope = 'all'", [$orderId]);

        $goods = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, productName, quantity, lineType, subscriptionRule FROM tl_consho_order_item WHERE pid = ? ORDER BY id', [$orderId]) as $row) {
            if (!OrderLineType::stored((string) $row['lineType'])->isGoods()) {
                continue;
            }

            $id = (int) $row['id'];
            $left = $whole ? BigDecimal::zero() : BigDecimal::of((string) $row['quantity'])->minus($withdrawn[$id] ?? '0')->minus($released[$id] ?? '0');

            $goods[$id] = [
                'name' => (string) $row['productName'],
                'quantity' => (string) $row['quantity'],
                'left' => (string) ($left->isNegative() ? BigDecimal::zero() : $left),
                'whole' => (int) $row['subscriptionRule'] > 0,
            ];
        }

        return $goods;
    }

    /**
     * @return list<array{number: string, receivedAt: int, scope: string, confirmed: bool}>
     */
    public function received(int $orderId): array
    {
        return array_map(
            static fn (array $row): array => [
                'number' => (string) $row['number'],
                'receivedAt' => (int) $row['receivedAt'],
                'scope' => (string) $row['scope'],
                'confirmed' => null !== $row['confirmationSentAt'],
            ],
            $this->connection->fetchAllAssociative('SELECT number, receivedAt, scope, confirmationSentAt FROM tl_consho_withdrawal WHERE pid = ? ORDER BY receivedAt, id', [$orderId]),
        );
    }
}
