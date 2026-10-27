<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Abo-1 (A4, P1): sale mode on the product, subscription rule on the price row.
 */
class SaleModeDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_product.php';
        require __DIR__.'/../../contao/dca/tl_consho_price.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testEinmalkaufIstDieVorgabeUndErsetztDieMigration(): void
    {
        // P1: contao:migrate fills the new column of all products with the default.
        $fields = $GLOBALS['TL_DCA']['tl_consho_product']['fields'];

        self::assertSame(['type' => 'boolean', 'default' => true], $fields['saleOnce']['sql']);
        self::assertSame(['type' => 'boolean', 'default' => false], $fields['saleSubscription']['sql']);
        self::assertArrayNotHasKey('withdrawalKind', $fields, 'DL7: die Widerrufsart folgt aus der Art des Typs');
    }

    public function testDieHakenKlappenLaufzeitUndRegelnAuf(): void
    {
        $dca = $GLOBALS['TL_DCA']['tl_consho_product'];

        self::assertSame(['saleOnce', 'saleSubscription'], $dca['palettes']['__selector__']);
        self::assertSame(['saleOnce' => 'entitlementMonths', 'saleSubscription' => 'subscriptionRules'], $dca['subpalettes']);
        self::assertTrue($dca['fields']['subscriptionRules']['eval']['multiple']);
        // The order is that of the product page: only the checkboxWizard
        // saves it the way it was dragged (Abo-1).
        self::assertSame('checkboxWizard', $dca['fields']['subscriptionRules']['inputType']);
        self::assertTrue($dca['fields']['subscriptionRules']['eval']['mandatory']);
    }

    public function testKeineNeueLegende(): void
    {
        /*
         * The comment on the palette names seven legends as the limit of
         * the tab bar. Since F-4 (`digital_legend`) there are eight — an
         * open point in Block Abo. Abo-1 adds none; this test records the
         * state so that the next one does not arrive unnoticed.
         */
        self::assertSame(8, substr_count($GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'], '{'));
    }

    public function testDieRegelStehtVornInDerPreismaskeUndNichtVornInDerSortierung(): void
    {
        $dca = $GLOBALS['TL_DCA']['tl_consho_price'];

        self::assertStringStartsWith('{price_legend},subscriptionRule,', $dca['palettes']['default']);
        // The parent view groups by the first field.
        self::assertSame('currency', $dca['list']['sorting']['fields'][0]);
        self::assertSame(0, $dca['fields']['subscriptionRule']['sql']['default']);
        // [0 => one-off purchase, 1 => …] is a list — without isAssociative
        // Contao would take the labels as values (Abo-1).
        self::assertTrue($dca['fields']['subscriptionRule']['eval']['isAssociative']);
    }
}
