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
     * The old single currency is gone from the form and, since its values
     * reached `currencies` and the start pages on real installations, from the
     * DCA too: `contao:migrate --with-deletes` drops the column.
     */
    public function testDieAlteEinzelwaehrungIstAusDerDcaVerschwunden(): void
    {
        self::assertNotContains('currency', $this->paletteFields());
        self::assertArrayNotHasKey('currency', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']);
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
     * @return list<string> the field names of the palette, without the legends
     */
    private function paletteFields(): array
    {
        $parts = preg_split('/[;,]/', $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default']) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part && !str_starts_with($part, '{')));
    }
}
