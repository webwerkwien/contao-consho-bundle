<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Die Maske zeigt Dezimalfelder mit dem Trennzeichen der Backend-Sprache
 * (DecimalFieldListener) — „2,5“. Speichern geht nur, wenn das Feld das Komma
 * wieder in einen Punkt verwandelt, und das tut Contao nur bei rgxp `digit`.
 * Ohne es landete „25,00“ in der Spalte: so stand es seit D-1 am Betrag einer
 * Zahlung, gefunden beim Bau des Listeners (2026-10-24).
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
     * Beträge zeigt die Maske mit zwei Stellen, Mengen ohne Nullen. Ein neues
     * Betragsfeld ohne die Markierung erschiene als „25,5“ — diese Liste
     * zwingt dazu, es einzuordnen.
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
