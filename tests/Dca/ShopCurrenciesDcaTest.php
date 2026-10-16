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
        // Bei knapp 300 Codes ist die Suche kein Komfort, sondern die Bedienung.
        self::assertTrue($field['eval']['chosen']);
        self::assertTrue($field['eval']['mandatory']);
        self::assertSame('blob', $field['sql']['type']);

        self::assertContains('currencies', $this->paletteFields());
    }

    /**
     * C-1c Schritt 4: aus der Maske, aber nicht aus der Tabelle. Die Werte
     * werden in Schritt 3 übernommen, die Spalte fällt erst nach bestandener
     * Abnahme auf c5 — wie `gross` an tl_consho_price in C-1b.
     */
    public function testDieAlteEinzelwaehrungIstAusDerPaletteVerschwundenAberNichtAusDerTabelle(): void
    {
        self::assertNotContains('currency', $this->paletteFields());

        $field = $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['currency'];

        self::assertSame('string', $field['sql']['type']);
        self::assertSame(3, $field['sql']['length']);
    }

    /**
     * Die Liste der Shops zeigt die erlaubten Währungen, nicht die alte
     * Einzelwährung: Die setzt seit C-1c keine Maske und kein Befehl mehr, ein
     * neuer Shop stand dort ohne Währung (gefunden bei der Abnahme I7).
     */
    public function testDieListeZeigtDieErlaubtenWaehrungen(): void
    {
        self::assertSame(['title', 'currencies'], $GLOBALS['TL_DCA']['tl_consho_shop']['list']['label']['fields']);
    }

    /**
     * „Lieber laut als still, ich denke das wäre auch internationaler."
     * (Michael, 2026-09-21)
     *
     * Ein neu angelegter Shop erbt kein EUR mehr aus dem Spalten-Vorgabewert.
     * `DbalMarketRepository` filtert `WHERE currency <> ''` — ohne gewählte
     * Währung fällt der Shop sichtbar aus den Märkten, statt still in Euro zu
     * verkaufen. Und ein eingebautes EUR wäre eurozentrisch in einem Produkt,
     * dessen Zweck gerade die Mehrwährungsfähigkeit ist.
     */
    public function testDieAlteEinzelwaehrungHatKeinenEuroVorgabewertMehr(): void
    {
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_shop']['fields']['currency']['sql']['default']);
    }

    /**
     * @return list<string> die Feldnamen der Palette, ohne die Legenden
     */
    private function paletteFields(): array
    {
        $parts = preg_split('/[;,]/', $GLOBALS['TL_DCA']['tl_consho_shop']['palettes']['default']) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => '' !== $part && !str_starts_with($part, '{')));
    }
}
