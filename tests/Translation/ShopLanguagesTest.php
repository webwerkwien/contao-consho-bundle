<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Translation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Against a real database, not against a mock: the question is which rows
 * the query selects, and no expected call answers that.
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
        // Deliberately created in reverse order: the list is sorted because
        // the query sorts — not because it already happened to be right here.
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
        // A freshly created shop cannot carry a default language: the
        // selection is empty as long as no root page points to it. Instead of
        // a block on saving, the first language applies here.
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
        // The state the fallback exists for: freshly created shop, no root
        // page yet. There is nothing to fall back on.
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
        // The touchstone of the whole multilingual support: for a
        // single-language shop nothing may change.
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
        // Not a contradiction but the consistent reading: a shop without a
        // second language needs no translation — even if it has none at all.
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

        // fr is the default language of shop 8, so not a translation language there.
        self::assertSame(['en'], $this->languages()->allTranslated());
    }

    public function testResetForgetsTheCachedDefaultLanguage(): void
    {
        // B-10: After a shop is saved, the projection computes within the
        // same request — with the cache it would see the old default language.
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

    // ── The audience: language and currency per root page (C-1c) ────────

    public function testEsNenntJeStartpunktSpracheUndWaehrung(): void
    {
        // Both are properties of the same access route and stand in the same
        // row (specification 4.0) — one query, one more field.
        // The English root page comes first in the page tree — the list is
        // sorted by language all the same.
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
        // Two German euro root pages (two domains) are one audience.
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
        // Not filtered out. Whoever wants to compute skips them themselves,
        // just as of() skips the empty language.
        //
        // ⚠️ The reason for this once was "whoever reports the gap needs the
        // row". Meanwhile the reporter exists — RootWithoutCurrency in
        // DbalShopScopeIssueSource —, and it reads tl_page itself, because it
        // has to name the page's title and ID. The row stays anyway: filtering
        // it out would change the behaviour of a tested method without any
        // caller gaining from it. See the comment on audiencesOf().
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
        // of() is the guarantee the translation page rests on: a currency more
        // or less must not move anything there. Root page 4 is the case that
        // did not exist before — the same language in two currencies is two
        // rows and still one language.
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
