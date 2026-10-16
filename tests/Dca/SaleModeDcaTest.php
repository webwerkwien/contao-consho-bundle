<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

/**
 * Abo-1 (A4, P1): Verkaufsart am Produkt, Aboregel an der Preiszeile.
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
        // P1: contao:migrate füllt die neue Spalte aller Produkte mit der Vorgabe.
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
        // Die Reihenfolge ist die der Produktseite: nur der checkboxWizard
        // speichert sie so, wie sie gezogen wurde (Abnahme Abo-1).
        self::assertSame('checkboxWizard', $dca['fields']['subscriptionRules']['inputType']);
        self::assertTrue($dca['fields']['subscriptionRules']['eval']['mandatory']);
    }

    public function testKeineNeueLegende(): void
    {
        /*
         * Der Kommentar an der Palette nennt sieben Legenden als Grenze der
         * Reiterleiste. Seit F-4 (`digital_legend`) sind es acht — gemessen
         * am 2026-09-29, als offener Punkt in docs/block-abo.md. Abo-1 fügt
         * keine hinzu; dieser Test hält den Stand fest, damit die nächste
         * nicht unbemerkt kommt.
         */
        self::assertSame(8, substr_count($GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'], '{'));
    }

    public function testDieRegelStehtVornInDerPreismaskeUndNichtVornInDerSortierung(): void
    {
        $dca = $GLOBALS['TL_DCA']['tl_consho_price'];

        self::assertStringStartsWith('{price_legend},subscriptionRule,', $dca['palettes']['default']);
        // Die Elternansicht gruppiert nach dem ersten Feld.
        self::assertSame('currency', $dca['list']['sorting']['fields'][0]);
        self::assertSame(0, $dca['fields']['subscriptionRule']['sql']['default']);
        // [0 => Einmalkauf, 1 => …] ist eine Liste — ohne isAssociative nähme
        // Contao die Beschriftungen als Werte (Abnahme Abo-1).
        self::assertTrue($dca['fields']['subscriptionRule']['eval']['isAssociative']);
    }
}
