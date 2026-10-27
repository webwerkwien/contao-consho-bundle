<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use PHPUnit\Framework\TestCase;

class ShopCurrenciesDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_shop.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testDieErlaubtenWaehrungenSindEinPflichtfeldMitMehrfachauswahl(): void
    {
        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['currencies'];

        self::assertSame('select', $field['inputType']);
        self::assertTrue($field['eval']['multiple']);
        // With almost 300 codes, the search is not a convenience but the way to use it.
        self::assertTrue($field['eval']['chosen']);
        self::assertTrue($field['eval']['mandatory']);
        self::assertSame('blob', $field['sql']['type']);

        self::assertContains('currencies', $this->paletteFields());
    }

    /**
     * C-1c step 4: out of the form, but not out of the table. The values are
     * taken over in step 3; the column is only dropped once that has been
     * verified on a real installation — like `gross` on tl_consho_price in C-1b.
     */
    public function testDieAlteEinzelwaehrungIstAusDerPaletteVerschwundenAberNichtAusDerTabelle(): void
    {
        self::assertNotContains('currency', $this->paletteFields());

        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['currency'];

        self::assertSame('string', $field['sql']['type']);
        self::assertSame(3, $field['sql']['length']);
    }

    /**
     * The list of shops shows the allowed currencies, not the old single
     * currency: since C-1c no form and no command sets it any more, and a new
     * shop stood there without a currency.
     */
    public function testDieListeZeigtDieErlaubtenWaehrungen(): void
    {
        self::assertSame(['title', 'currencies'], $GLOBALS['TL_DCA']['tl_consho_shop']['list']['label']['fields']);
    }

    /**
     * Loud rather than silent — which also suits an international product.
     *
     * A newly created shop no longer inherits EUR from the column default.
     * `DbalMarketRepository` filters `WHERE currency <> ''` — without a chosen
     * currency the shop visibly drops out of the markets instead of silently
     * selling in euros. And a built-in EUR would be eurocentric in a product
     * whose very purpose is multi-currency capability.
     */
    public function testDieAlteEinzelwaehrungHatKeinenEuroVorgabewertMehr(): void
    {
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['currency']['sql']['default']);
    }

    /**
     * @return list<string> the field names of the palette, without the legends
     */
    private function paletteFields(): array
    {
        $parts = preg_split('/[;,]/', $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default']) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part && !str_starts_with($part, '{')));
    }
}
