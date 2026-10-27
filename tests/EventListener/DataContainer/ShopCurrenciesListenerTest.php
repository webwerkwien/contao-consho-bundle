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
        // Only the branch set by ourselves, not the whole language tree.
        unset($GLOBALS['TL_LANG']['CONSHO'], $GLOBALS['TL_LANGUAGE']);
    }

    /**
     * The whole list, not the ones sold — this field determines what is
     * possible, the price form follows from it.
     */
    public function testDieOptionenUmfassenDieVollstaendigeIsoListe(): void
    {
        $options = $this->listener->options();

        self::assertCount(\count(Currencies::getCurrencyCodes()), $options);
        self::assertArrayHasKey('EUR', $options);

        // The actual touchstone: a currency that no shop carries. EUR, CHF and
        // USD would also be in the filtered list of the price form and therefore
        // would not distinguish anything.
        self::assertArrayHasKey('MNT', $options);
    }

    /**
     * The code is in the label because `chosen` searches the label — what is
     * typed is "USD", not "US-Dollar".
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
     * A language that looks like a language falls back to its parent in
     * Symfony — `kl-ingon` ends up at Kalaallisut. One that does not makes the
     * Intl reader throw (measured: "12345" and path strings). The form must
     * not break on that.
     *
     * Checked on CHF, not on EUR: "Euro" is the same in German and English and
     * could not tell the fallback apart from any other exit.
     */
    public function testEineUnlesbareBackendSpracheFaelltAufEnglischZurueck(): void
    {
        $GLOBALS['TL_LANGUAGE'] = '12345';

        self::assertSame('Swiss Franc (CHF)', $this->listener->options()['CHF']);
    }

    /**
     * Contao delivers a language TAG with a hyphen
     * (`LocaleUtil::formatAsLanguageTag()`), Symfony's data directory is named
     * with an underscore. Without the rewrite in the listener `de-CH` misses
     * the file `de_CH` and falls back to `de` via `Locale::getFallback()` — the
     * same text for 291 of 294 codes, not for three.
     *
     * BYN is one of them: Swiss Standard German has no ß.
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
     * The callback's job: no empty selection.
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
     * What `mandatory` does not cover: Contao offers a stored value outside the
     * options again as "(unknown)" and takes it back on save.
     */
    public function testEinUnbekannterCodeWirdAbgelehnt(): void
    {
        $this->expectExceptionMessage('unbekannt: XYZ');

        $this->listener->save(serialize(['EUR', 'XYZ']));
    }
}
