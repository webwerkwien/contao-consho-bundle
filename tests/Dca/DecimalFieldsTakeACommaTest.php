<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * The form shows decimal fields with the separator of the backend language
 * (DecimalFieldListener) — "2,5". Saving only works if the field turns the
 * comma back into a point, and Contao does that only for rgxp `digit`.
 * Without it, "25,00" ended up in the column: that is how it stood on the
 * amount of a payment since D-1.
 */
class DecimalFieldsTakeACommaTest extends TestCase
{
    public function testJedesBearbeitbareDezimalfeldNimmtEinKomma(): void
    {
        $missing = [];

        // Brings its own load and save callbacks (GuaranteeYears), registered
        // by attribute, so the DCA file does not show them.
        $own = ['tl_consho_product.durabilityYears'];

        foreach ($this->decimalFields() as $key => $field) {
            if (!($field['eval']['readonly'] ?? false) && !\in_array($key, $own, true) && 'digit' !== ($field['eval']['rgxp'] ?? null)) {
                $missing[] = $key;
            }
        }

        self::assertSame([], $missing);
    }

    /**
     * The form shows amounts with two decimals, quantities without trailing
     * zeros. A new amount field without the marker would appear as "25,5" — this
     * list forces it to be classified.
     */
    public function testBetragsfelderSindMarkiert(): void
    {
        $money = [];

        foreach ($this->decimalFields() as $key => $field) {
            if ($field['eval']['conshoMoney'] ?? false) {
                $money[] = $key;
            }
        }

        sort($money);

        self::assertSame([
            'tl_consho_cart_rule.amount',
            'tl_consho_cart_rule.minimumOrder',
            'tl_consho_order.grandTotal',
            'tl_consho_order.netTotal',
            'tl_consho_order.taxTotal',
            'tl_consho_order_item.lineTotal',
            'tl_consho_order_item.taxAmount',
            'tl_consho_order_item.unitPrice',
            'tl_consho_payment.amount',
            'tl_consho_product.voucherValue',
            'tl_consho_subscription.unitNet',
            'tl_consho_voucher.balance',
            'tl_consho_voucher.faceValue',
            'tl_consho_voucher.paidAmount',
            'tl_consho_voucher_booking.amount',
            'tl_consho_voucher_booking.balanceAfter',
            'tl_consho_withdrawal.refundAmount',
            'tl_consho_withdrawal.refundShipping',
            'tl_consho_withdrawal.valueCompensation',
        ], $money);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decimalFields(): array
    {
        $fields = [];

        foreach (glob(__DIR__.'/../../contao/dca/tl_consho_*.php') ?: [] as $file) {
            $GLOBALS['TL_DCA'] = [];
            include $file;

            foreach ($GLOBALS['TL_DCA'] as $table => $dca) {
                foreach ($dca['fields'] ?? [] as $name => $config) {
                    if ('text' === ($config['inputType'] ?? null) && 'decimal' === ($config['sql']['type'] ?? null)) {
                        $fields[$table.'.'.$name] = $config;
                    }
                }
            }
        }

        unset($GLOBALS['TL_DCA']);

        return $fields;
    }
}
