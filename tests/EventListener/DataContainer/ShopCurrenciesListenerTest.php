<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ShopCurrenciesListener;

class ShopCurrenciesListenerTest extends TestCase
{
    private ShopCurrenciesListener $listener;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'shopCurrenciesEmpty' => 'keine Währung gewählt',
            'shopCurrencyUnknown' => 'unbekannt: %s',
        ];
        $GLOBALS['TL_LANGUAGE'] = 'de';

        $this->listener = new ShopCurrenciesListener();
    }

    protected function tearDown(): void
    {
        // Nur der selbst gesetzte Ast, nicht der ganze Sprachbaum.
        unset($GLOBALS['TL_LANG']['CONSHO'], $GLOBALS['TL_LANGUAGE']);
    }

    /**
     * Die ganze Liste, nicht die verkauften — dieses Feld bestimmt, was möglich
     * ist, die Preismaske folgt daraus.
     */
    public function testDieOptionenUmfassenDieVollstaendigeIsoListe(): void
    {
        $options = $this->listener->options();

        self::assertCount(\count(Currencies::getCurrencyCodes()), $options);
        self::assertArrayHasKey('EUR', $options);

        // Der eigentliche Prüfstein: eine Währung, die kein Shop führt. EUR,
        // CHF und USD stünden auch in der gefilterten Liste der Preismaske und
        // unterschieden deshalb nichts.
        self::assertArrayHasKey('MNT', $options);
    }

    /**
     * Der Code steht in der Beschriftung, weil `chosen` die Beschriftung
     * durchsucht — getippt wird „USD", nicht „US-Dollar".
     */
    public function testDieBeschriftungNenntNamenUndCode(): void
    {
        self::assertSame('Euro (EUR)', $this->listener->options()['EUR']);
    }

    public function testDieBeschriftungFolgtDerBackendSprache(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'en';

        self::assertSame('Swiss Franc (CHF)', $this->listener->options()['CHF']);
    }

    /**
     * Eine Sprache, die wie eine Sprache aussieht, fällt bei Symfony auf die
     * übergeordnete zurück — `kl-ingon` landet bei Kalaallisut. Eine, die das
     * nicht tut, lässt den Intl-Leser werfen (gemessen am 2026-09-21: „12345"
     * und Pfadangaben). Die Maske darf daran nicht zerbrechen.
     *
     * Geprüft an CHF, nicht an EUR: „Euro" heißt auf Deutsch wie auf Englisch
     * gleich und könnte den Rückfall von jedem beliebigen Ausgang nicht
     * unterscheiden.
     */
    public function testEineUnlesbareBackendSpracheFaelltAufEnglischZurueck(): void
    {
        $GLOBALS['TL_LANGUAGE'] = '12345';

        self::assertSame('Swiss Franc (CHF)', $this->listener->options()['CHF']);
    }

    /**
     * Contao liefert einen Sprach-TAG mit Bindestrich
     * (`LocaleUtil::formatAsLanguageTag()`), Symfonys Datenverzeichnis heißt
     * mit Unterstrich. Ohne die Umschreibung im Listener verfehlt `de-CH` die
     * Datei `de_CH` und fällt über `Locale::getFallback()` auf `de` zurück —
     * bei 291 von 294 Codes derselbe Text, bei dreien nicht.
     *
     * BYN ist einer davon: Schweizer Hochdeutsch kennt kein ß.
     */
    public function testEinRegionalerSprachTagErreichtDieRegionalenNamen(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de-CH';

        self::assertSame('Weissrussischer Rubel (BYN)', $this->listener->options()['BYN']);

        $GLOBALS['TL_LANGUAGE'] = 'de';

        self::assertSame('Weißrussischer Rubel (BYN)', $this->listener->options()['BYN']);
    }

    public function testDieAuswahlWirdSortiertUndOhneDoppelteGespeichert(): void
    {
        self::assertSame(['CHF', 'EUR', 'USD'], unserialize($this->listener->save(serialize(['USD', 'eur', 'CHF', 'EUR']))));
    }

    /**
     * Der Auftrag des Callbacks: keine leere Auswahl.
     */
    public function testEineLeereAuswahlWirdAbgelehnt(): void
    {
        $this->expectExceptionMessage('keine Währung gewählt');

        $this->listener->save(serialize([]));
    }

    public function testEinLeeresFeldWirdAbgelehnt(): void
    {
        $this->expectExceptionMessage('keine Währung gewählt');

        $this->listener->save('');
    }

    /**
     * Das, was `mandatory` nicht abdeckt: Contao bietet einen gespeicherten Wert
     * außerhalb der Optionen als „(unbekannt)" wieder an und nimmt ihn beim
     * Speichern zurück.
     */
    public function testEinUnbekannterCodeWirdAbgelehnt(): void
    {
        $this->expectExceptionMessage('unbekannt: XYZ');

        $this->listener->save(serialize(['EUR', 'XYZ']));
    }
}
