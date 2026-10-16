<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Gegen eine echte Datenbank, nicht gegen einen Mock: Die Frage ist, welche
 * Zeilen die Abfrage auswählt, und das beantwortet kein erwarteter Aufruf.
 */
class ShopLanguagesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                type TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                consho_currency TEXT NOT NULL DEFAULT ''
            )",
        );

        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    public function testItListsTheLanguagesOfTheRootPagesOfThatShop(): void
    {
        // Absichtlich in umgekehrter Reihenfolge angelegt: Die Liste ist
        // sortiert, weil die Abfrage sortiert — nicht, weil sie hier schon
        // stimmte.
        $this->root(1, 'en', shop: 7);
        $this->root(2, 'de', shop: 7);
        $this->root(3, 'fr', shop: 8);
        $this->shop(7, 'de');

        self::assertSame(['de', 'en'], $this->languages()->of(7));
    }

    public function testItIgnoresPagesThatAreNotRoots(): void
    {
        $this->root(1, 'de', shop: 7);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'regular', 'language' => 'en', 'consho_shop' => 7]);
        $this->shop(7, 'de');

        self::assertSame(['de'], $this->languages()->of(7));
    }

    public function testTheSameLanguageOnTwoRootsIsListedOnce(): void
    {
        $this->root(1, 'de', shop: 7);
        $this->root(2, 'de', shop: 7);
        $this->shop(7, 'de');

        self::assertSame(['de'], $this->languages()->of(7));
    }

    public function testItReportsTheFallbackLanguageOfTheShop(): void
    {
        $this->root(1, 'de', shop: 7);
        $this->root(2, 'en', shop: 7);
        $this->shop(7, 'en');

        self::assertSame('en', $this->languages()->fallbackOf(7));
    }

    public function testWithoutAStoredDefaultTheFirstLanguageIsUsed(): void
    {
        // Ein frisch angelegter Shop kann keine Standardsprache tragen: Die
        // Auswahl ist leer, solange kein Startpunkt auf ihn zeigt. Statt einer
        // Klemme beim Speichern greift hier die erste Sprache.
        $this->root(1, 'de', shop: 7);
        $this->root(2, 'en', shop: 7);
        $this->shop(7, '');

        self::assertSame('de', $this->languages()->fallbackOf(7));
    }

    public function testAShopWithoutRootsHasNoLanguages(): void
    {
        $this->shop(7, 'de');

        self::assertSame([], $this->languages()->of(7));
    }

    public function testAShopWithoutRootsHasNoFallbackLanguage(): void
    {
        // Der Zustand, für den es den Rückfall gibt: frisch angelegter Shop,
        // noch kein Startpunkt. Es gibt nichts, worauf zurückzufallen wäre.
        $this->shop(7, '');

        self::assertSame('', $this->languages()->fallbackOf(7));
    }

    public function testItNamesTheLanguagesBesideTheFallback(): void
    {
        $this->root(1, 'de', shop: 7);
        $this->root(2, 'en', shop: 7);
        $this->shop(7, 'de');

        self::assertSame(['en'], $this->languages()->translatedOf(7));
    }

    public function testAShopWithoutRootsHasNothingToTranslate(): void
    {
        $this->shop(7, '');

        self::assertSame([], $this->languages()->translatedOf(7));
    }

    public function testAShopWithOneLanguageIsMonolingual(): void
    {
        // Der Prüfstein der ganzen Mehrsprachigkeit: Für einen einsprachigen
        // Shop darf sich nichts ändern.
        $this->root(1, 'de', shop: 7);
        $this->shop(7, 'de');

        self::assertTrue($this->languages()->isMonolingual(7));
    }

    public function testAShopWithTwoLanguagesIsNotMonolingual(): void
    {
        $this->root(1, 'de', shop: 7);
        $this->root(2, 'en', shop: 7);
        $this->shop(7, 'de');

        self::assertFalse($this->languages()->isMonolingual(7));
    }

    public function testAShopWithoutRootsCountsAsMonolingual(): void
    {
        // Kein Widerspruch, sondern die schlüssige Lesart: Ein Shop ohne zweite
        // Sprache braucht keine Übersetzung — auch wenn er gar keine hat.
        $this->shop(7, '');

        self::assertTrue($this->languages()->isMonolingual(7));
    }

    public function testItCollectsTheLanguagesOfEveryShop(): void
    {
        $this->root(1, 'de', shop: 7);
        $this->root(2, 'en', shop: 7);
        $this->root(3, 'fr', shop: 8);
        $this->shop(7, 'de');
        $this->shop(8, 'fr');

        // fr ist Standardsprache von Shop 8, also dort keine Übersetzungssprache.
        self::assertSame(['en'], $this->languages()->allTranslated());
    }

    public function testResetForgetsTheCachedDefaultLanguage(): void
    {
        // Befund 3 aus B-10: Nach dem Speichern eines Shops rechnet die
        // Projektion im selben Request — mit dem Cache sähe sie die alte
        // Standardsprache.
        $this->root(1, 'de', shop: 7);
        $this->root(2, 'en', shop: 7);
        $this->shop(7, 'de');
        $languages = $this->languages();

        self::assertSame('de', $languages->fallbackOf(7));

        $this->connection->update('tl_consho_shop', ['fallbackLanguage' => 'en'], ['id' => 7]);

        self::assertSame('de', $languages->fallbackOf(7));

        $languages->reset();

        self::assertSame('en', $languages->fallbackOf(7));
    }

    public function testResetForgetsTheCachedLanguages(): void
    {
        $this->root(1, 'de', shop: 7);
        $this->shop(7, 'de');
        $languages = $this->languages();

        self::assertSame(['de'], $languages->of(7));

        $this->root(2, 'it', shop: 7);

        self::assertSame(['de'], $languages->of(7));

        $languages->reset();

        self::assertSame(['de', 'it'], $languages->of(7));
    }

    // ── Das Publikum: Sprache und Währung je Startpunkt (C-1c) ──────────

    public function testEsNenntJeStartpunktSpracheUndWaehrung(): void
    {
        // Beides sind Eigenschaften desselben Zugangswegs und stehen in
        // derselben Zeile (Spezifikation 4.0) — eine Abfrage, ein Feld mehr.
        // Der englische Startpunkt steht zuerst im Seitenbaum — die Liste ist
        // trotzdem nach Sprache sortiert.
        $this->root(1, 'en', shop: 7, currency: 'GBP');
        $this->root(2, 'de', shop: 7, currency: 'EUR');
        $this->root(3, 'fr', shop: 8, currency: 'EUR');
        $this->shop(7, 'de');

        self::assertSame(
            [['language' => 'de', 'currency' => 'EUR'], ['language' => 'en', 'currency' => 'GBP']],
            $this->languages()->audiencesOf(7),
        );
    }

    public function testEsZaehltDenselbenZugangswegEinmal(): void
    {
        // Zwei deutsche Euro-Startpunkte (zwei Domains) sind ein Publikum.
        $this->root(1, 'de', shop: 7, currency: 'EUR');
        $this->root(2, 'de', shop: 7, currency: 'EUR');
        $this->shop(7, 'de');

        self::assertSame([['language' => 'de', 'currency' => 'EUR']], $this->languages()->audiencesOf(7));
    }

    public function testDieselbeSpracheInZweiWaehrungenSindZweiZugaenge(): void
    {
        $this->root(1, 'de', shop: 7, currency: 'EUR');
        $this->root(2, 'de', shop: 7, currency: 'CHF');
        $this->shop(7, 'de');

        self::assertSame(
            [['language' => 'de', 'currency' => 'CHF'], ['language' => 'de', 'currency' => 'EUR']],
            $this->languages()->audiencesOf(7),
        );
    }

    public function testEinStartpunktOhneWaehrungWirdMitLeererWaehrungGenannt(): void
    {
        // Nicht weggefiltert. Wer rechnen will, übergeht sie selbst, so wie
        // of() die leere Sprache.
        //
        // ⚠️ Die Begründung dafür war einmal „wer die Lücke meldet, braucht
        // die Zeile". Seit Aufgabe 7 gibt es den Melder — RootWithoutCurrency
        // in DbalShopScopeIssueSource —, und er liest tl_page selbst, weil er
        // Titel und ID der Seite nennen muss. Die Zeile bleibt trotzdem: Ihr
        // Wegfiltern änderte das Verhalten einer getesteten Methode, ohne dass
        // ein Aufrufer etwas davon hätte. Siehe den Kommentar an audiencesOf().
        $this->root(1, 'de', shop: 7, currency: '');
        $this->shop(7, 'de');

        self::assertSame([['language' => 'de', 'currency' => '']], $this->languages()->audiencesOf(7));
    }

    public function testEinShopOhneStartpunkteHatKeinPublikum(): void
    {
        $this->shop(7, 'de');

        self::assertSame([], $this->languages()->audiencesOf(7));
    }

    public function testSeitenDieKeineStartpunkteSindZaehlenNicht(): void
    {
        $this->root(1, 'de', shop: 7, currency: 'EUR');
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'regular', 'language' => 'en', 'consho_shop' => 7, 'consho_currency' => 'GBP']);
        $this->shop(7, 'de');

        self::assertSame([['language' => 'de', 'currency' => 'EUR']], $this->languages()->audiencesOf(7));
    }

    public function testResetVergisstAuchDasGemerktePublikum(): void
    {
        $this->root(1, 'de', shop: 7, currency: 'EUR');
        $this->shop(7, 'de');
        $languages = $this->languages();

        self::assertSame([['language' => 'de', 'currency' => 'EUR']], $languages->audiencesOf(7));

        $this->root(2, 'en', shop: 7, currency: 'GBP');

        self::assertSame([['language' => 'de', 'currency' => 'EUR']], $languages->audiencesOf(7));

        $languages->reset();

        self::assertCount(2, $languages->audiencesOf(7));
    }

    public function testDieSprachlisteAendertSichDadurchNicht(): void
    {
        // of() ist die Zusicherung, auf der die Übersetzungsseite steht: eine
        // Währung mehr oder weniger darf dort nichts bewegen. Startpunkt 4 ist
        // der Fall, den es vorher nicht gab — dieselbe Sprache in zwei
        // Währungen sind zwei Zeilen und trotzdem eine Sprache.
        $this->root(1, 'de', shop: 7, currency: 'EUR');
        $this->root(2, 'en', shop: 7, currency: '');
        $this->root(3, '', shop: 7, currency: 'CHF');
        $this->root(4, 'de', shop: 7, currency: 'CHF');
        $this->shop(7, 'de');

        self::assertSame(['de', 'en'], $this->languages()->of(7));
    }

    private function languages(): ShopLanguages
    {
        return new ShopLanguages($this->connection);
    }

    private function root(int $id, string $language, int $shop, string $currency = 'EUR'): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'type' => 'root', 'language' => $language, 'consho_shop' => $shop, 'consho_currency' => $currency]);
    }

    private function shop(int $id, string $fallback): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'fallbackLanguage' => $fallback]);
    }
}
