<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;

/**
 * Turns the paid voucher lines of an order into vouchers (block
 * Wertgutscheine, WG-4).
 *
 * Called by DigitalRelease inside its transaction, on the order it has
 * locked: the release is what every place money enters already calls
 * (checkout, payment, cron), and it reports what it hands out as delivered —
 * the order settles, an invoice "on shipment" is issued. One voucher per
 * piece: three vouchers of 25 € are three codes, each to give away.
 *
 * Only once the order is paid, whatever the payment method — "Auf Rechnung"
 * included (WG6): a voucher is money. No wait for the withdrawal period:
 * nothing is performed early, and a withdrawn purchase blocks the code (WG7,
 * WG-6).
 *
 *  - value: frozen at the line (`voucherValue`); without one, what was paid
 *    for a piece;
 *  - paid: the line's gross per piece — the tax base once it is spent
 *    (Art. 73a MwStSystRL);
 *  - currency and shop: the order's;
 *  - valid until: `voucherValidMonths` of the main product, counted from
 *    today, as the last day; none for 0 (WG10).
 */
final class VoucherIssuer
{
    public function __construct(
        private readonly Connection $connection,
        private readonly VoucherLedger $ledger,
    ) {
    }

    /**
     * @param array<string, mixed>                                    $order the locked order row
     * @param array<int, array{product: int, quantity: string}> $lines order item id => article and open quantity
     *
     * @return array<int, list<int>> order item id => the vouchers issued for it
     */
    public function issue(array $order, array $lines, int $now): array
    {
        $issued = [];

        foreach ($lines as $itemId => $line) {
            $item = $this->connection->fetchAssociative('SELECT quantity, lineTotal, voucherValue, voucherSinglePurpose, taxClass, taxRate, taxCountry FROM tl_consho_order_item WHERE id = ?', [$itemId]);

            if (false === $item) {
                continue;
            }

            $paid = BigDecimal::of((string) $item['lineTotal'])->dividedBy(BigDecimal::of((string) $item['quantity']), 4, RoundingMode::HalfUp);
            $value = BigDecimal::of(is_numeric($item['voucherValue']) ? (string) $item['voucherValue'] : '0');
            $value = $value->isPositive() ? $value : $paid;
            $validUntil = $this->validUntil($line['product'], $now);

            // EZ-5: what the line was sold as, not what the product says today.
            $singlePurpose = (bool) $item['voucherSinglePurpose']
                ? new SinglePurpose((int) $item['taxClass'], (string) $item['taxRate'], (string) $item['taxCountry'])
                : null;

            $pieces = BigDecimal::of($line['quantity'])->toScale(0, RoundingMode::Down)->toInt();

            for ($i = 0; $i < $pieces; ++$i) {
                $issued[$itemId][] = $this->ledger->issue(
                    (int) $order['shop'],
                    (string) $order['currency'],
                    (string) $value,
                    (string) $paid,
                    $validUntil,
                    'purchase',
                    $now,
                    (int) $order['id'],
                    $itemId,
                    singlePurpose: $singlePurpose,
                );
            }
        }

        return $issued;
    }

    /**
     * The last day a voucher of this article can be spent, as Contao stores a
     * date (midnight in the installation's zone); null for unlimited.
     */
    private function validUntil(int $articleId, int $now): int|null
    {
        $months = (int) $this->connection->fetchOne(
            'SELECT m.voucherValidMonths FROM tl_consho_product a JOIN tl_consho_product m ON m.id = CASE WHEN a.pid > 0 THEN a.pid ELSE a.id END WHERE a.id = ?',
            [$articleId],
        );

        if ($months < 1) {
            return null;
        }

        $today = (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        // Calendar months without PHP's overflow (WG-7): 31 January
        // plus one month is 28/29 February, not 3 March.
        $target = $today->modify('first day of this month')->modify(\sprintf('+%d months', $months));

        return $target
            ->setDate((int) $target->format('Y'), (int) $target->format('n'), min((int) $today->format('j'), (int) $target->format('t')))
            ->setTime(0, 0)
            ->getTimestamp()
        ;
    }
}
