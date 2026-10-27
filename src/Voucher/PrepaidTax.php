<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * What single-purpose vouchers paid on an order (EZ-4): per voucher, its
 * payments less what went back to it, with the tax their sale already
 * carried. Named as the payment names it ("Gutschein …MDSN"), never by the
 * whole code. A voucher with nothing left paid drops out.
 *
 * One reader for the invoice snapshot, the order page and the order mails,
 * so that "already taxed" and "remaining tax" read the same everywhere.
 */
final class PrepaidTax
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}>
     */
    public function of(int $orderId): array
    {
        if ($orderId < 1) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, reference, method, amount, prepaidTaxRate, prepaidTaxCountry, prepaidTax FROM tl_consho_payment WHERE pid = ? AND prepaidTaxRate > 0 ORDER BY receivedAt, id',
            [$orderId],
        );

        $vouchers = [];

        foreach ($rows as $row) {
            // The anonymisation empties the reference: such a payment stands on
            // its own rather than merging vouchers, rates and countries.
            $key = '' !== (string) $row['reference'] ? (string) $row['reference'] : 'payment:'.$row['id'];
            $amount = BigDecimal::of((string) $row['amount']);

            // The redemption names the voucher; a refund to it is a counter-entry.
            $vouchers[$key] ??= ['label' => '', 'country' => strtoupper((string) $row['prepaidTaxCountry']), 'rate' => (string) BigDecimal::of((string) $row['prepaidTaxRate'])->toScale(4), 'gross' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];

            if ($amount->isPositive() && '' === $vouchers[$key]['label']) {
                $vouchers[$key]['label'] = (string) $row['method'];
            }

            $vouchers[$key]['gross'] = $vouchers[$key]['gross']->plus($amount);
            $vouchers[$key]['tax'] = $vouchers[$key]['tax']->plus((string) $row['prepaidTax']);
        }

        $prepaid = [];

        foreach ($vouchers as $voucher) {
            if (!$voucher['gross']->isPositive()) {
                continue;
            }

            $prepaid[] = [
                'label' => $voucher['label'],
                'country' => $voucher['country'],
                'rate' => $voucher['rate'],
                'net' => (string) $voucher['gross']->minus($voucher['tax'])->toScale(4),
                'tax' => (string) $voucher['tax']->toScale(4),
                'gross' => (string) $voucher['gross']->toScale(4),
            ];
        }

        return $prepaid;
    }
}
