<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Brick\Math\BigDecimal;

/**
 * A counter-booking that corrects a wrong row in tl_consho_payment.
 *
 * Locked rows are never edited; a wrong one is corrected by a row with
 * `method` = METHOD, the reason in `reference`, and `refundOf` = the wrong
 * row. It moves no money: wherever amounts are read it is netted into the
 * row it corrects, so a payment of 100 corrected by -90 counts as a payment
 * of 10 — not as a payment of 100 and a refund of 90, and the customer never
 * sees the wrong booking.
 */
final class CorrectionRow
{
    public const METHOD = 'correction';

    public static function is(string $method): bool
    {
        return self::METHOD === $method;
    }

    /**
     * The rows with each correction netted into the row it corrects.
     *
     * A row corrected to zero is left out: nothing was received. A correction
     * whose row is not among the given ones, or is itself a correction, stays
     * a row of its own, so no amount is lost from the sum.
     *
     * @template T of array{id: int, amount: string, method: string, refundOf: int}
     *
     * @param list<T> $rows
     *
     * @return list<T> in the order of the rows
     */
    public static function fold(array $rows): array
    {
        $targets = [];

        foreach ($rows as $row) {
            if (!self::is($row['method'])) {
                $targets[$row['id']] = true;
            }
        }

        $folded = [];

        foreach ($rows as $row) {
            if (self::is($row['method']) && isset($targets[$row['refundOf']])) {
                $folded[$row['refundOf']] = ($folded[$row['refundOf']] ?? BigDecimal::zero())->plus($row['amount']);
            }
        }

        $result = [];

        foreach ($rows as $row) {
            if (self::is($row['method']) && isset($targets[$row['refundOf']])) {
                continue;
            }

            if (isset($folded[$row['id']])) {
                $amount = $folded[$row['id']]->plus($row['amount']);

                if ($amount->isZero()) {
                    continue;
                }

                $row['amount'] = (string) $amount->toScale(4);
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * The amounts of fold(), for the balance.
     *
     * @param list<array{id: int, amount: string, method: string, refundOf: int}> $rows
     *
     * @return list<string>
     */
    public static function net(array $rows): array
    {
        return array_map(static fn (array $row): string => $row['amount'], self::fold($rows));
    }
}
