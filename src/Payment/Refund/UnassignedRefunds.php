<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment\Refund;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Payment\ChargebackRow;
use Webwerkwien\ContaoConshoBundle\Payment\CorrectionRow;

/**
 * Refund rows that name no payment (block Geldflüsse, J3): money paid back
 * before refunds were tied to their payment, where the migration could not
 * tell which one it was (E2), or a negative row typed by hand.
 *
 * Without the payment, what is left of each payment reads too high, and
 * "Erstatten" would offer money that is already back. "Zahlung zuordnen" on
 * the page "Geldflüsse" ties the row to its payment (RefundPayout::assign()).
 *
 * Not counted: a voucher's refund (its money went back onto the voucher), a
 * chargeback and a correction (both always name their payment).
 */
final class UnassignedRefunds
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $row a row of tl_consho_payment
     */
    public static function is(array $row): bool
    {
        return BigDecimal::of((string) ($row['amount'] ?? '0'))->isNegative()
            && 0 === (int) ($row['refundOf'] ?? 0)
            && !str_starts_with((string) ($row['reference'] ?? ''), 'voucher:')
            && !ChargebackRow::is((string) ($row['method'] ?? ''))
            && !CorrectionRow::is((string) ($row['method'] ?? ''));
    }

    /**
     * @return array<int, array{order: int, shop: int, number: string}> keyed by row id, by shop and order
     */
    public function all(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT p.id, p.amount, p.refundOf, p.reference, p.method, o.id AS orderId, o.shop, o.orderNumber
               FROM tl_consho_payment p
               JOIN tl_consho_order o ON o.id = p.pid
              WHERE p.amount < 0 AND p.refundOf = 0 AND p.reference NOT LIKE 'voucher:%'
              ORDER BY o.shop, o.id, p.id",
        );

        $unassigned = [];

        foreach ($rows as $row) {
            if (self::is($row)) {
                $unassigned[(int) $row['id']] = ['order' => (int) $row['orderId'], 'shop' => (int) $row['shop'], 'number' => (string) $row['orderNumber']];
            }
        }

        return $unassigned;
    }
}
