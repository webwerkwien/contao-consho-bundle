<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Writing and updating the visibility projection (B-10, 5.7) — against
 * a real database.
 *
 * VisibilityRowsTest checks which rows belong in it. This is about
 * refresh, rebuild and the language sync putting exactly these rows there —
 * and not leaving old ones behind.
 */
class VisibilityProjectorTest extends TestCase
{
    use CatalogSchema;

    // Ten digits like a real timestamp — see VisibilityCriteriaTest.
    private const NOW = 1_790_000_000;
    private const SHOP = 1;

    private Connection $connection;
    private ShopLanguages $languages;
    private ProjectionState $state;
    private VisibilityProjector $projector;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);

        // de is the default language, fr a translation language.
        $this->insertShop(['id' => self::SHOP, 'title' => 'Shop', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', self::SHOP);
        $this->insertRootPage(2, 'fr', self::SHOP);

        $this->languages = new ShopLanguages($this->connection);
        $this->state = new ProjectionState($this->connection);
        $this->projector = new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            $this->languages,
            $this->state,
        );
    }

    public function testRefreshWritesMainAndVariantRowsPerLanguage(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->variant(2, 1);
        $this->listPrice(2, '12.0000');

        // Row of another main product: refresh([1]) must not touch it.
        $this->insertVisibility(['tstamp' => 1, 'pid' => 9, 'main' => 9, 'variant' => 0, 'shop' => self::SHOP, 'language' => 'de', 'net' => '1.0000', 'gross' => '1.2000']);

        self::assertTrue($this->projector->refresh([1], self::NOW));

        self::assertSame([
            '1:de/EUR H 1 (main 1) 12.0000',
            '1:de/EUR V 2 (main 1) -',
            '1:de/EUR H 9 (main 9) 1.2000',
            '1:fr/EUR H 1 (main 1) 12.0000',
            '1:fr/EUR V 2 (main 1) -',
        ], $this->stored());

        self::assertSame([self::NOW], array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT tstamp FROM tl_consho_product_visibility WHERE main = 1')));
    }

    public function testRefreshRemovesAnUnpublishedProduct(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->refresh([1], self::NOW);

        $this->connection->update('tl_consho_product', ['published' => 0], ['id' => 1]);

        self::assertTrue($this->projector->refresh([1], self::NOW));
        self::assertSame([], $this->stored());
    }

    public function testARehungVariantTakesTheLastVariantFromItsOldMainProduct(): void
    {
        // Main product 1 is only visible and priced through variant 3. If 3 is
        // moved elsewhere, 1 only remains in the stored rows of 3.
        $this->main(1);
        $this->main(2);
        $this->variant(3, 1);
        $this->listPrice(3, '12.0000');
        $this->projector->refresh([1, 2], self::NOW);

        // The fr variant row without a main row is intended: variants() does not
        // check the main product's translation, every reader comes through the
        // main row (see VisibilityRows::variants()).
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000', '1:de/EUR V 3 (main 1) -', '1:fr/EUR V 3 (main 1) -'], $this->stored());

        $this->connection->update('tl_consho_product', ['pid' => 2], ['id' => 3]);

        self::assertTrue($this->projector->refresh([3], self::NOW));
        self::assertSame(['1:de/EUR H 2 (main 2) 12.0000', '1:de/EUR V 3 (main 2) -', '1:fr/EUR V 3 (main 2) -'], $this->stored());
    }

    public function testReportingOnlyTheNewMainProductOfARehungVariantAlsoClearsTheOldOne(): void
    {
        // Without regard to the stored row of 3 the insert would hit
        // the unique key (pid, shop, language, currency).
        $this->main(1);
        $this->main(2);
        $this->variant(3, 1);
        $this->listPrice(3, '12.0000');
        $this->projector->refresh([1, 2], self::NOW);

        $this->connection->update('tl_consho_product', ['pid' => 2], ['id' => 3]);

        self::assertTrue($this->projector->refresh([2], self::NOW));
        self::assertSame(['1:de/EUR H 2 (main 2) 12.0000', '1:de/EUR V 3 (main 2) -', '1:fr/EUR V 3 (main 2) -'], $this->stored());
    }

    public function testADeletedProductLosesItsRows(): void
    {
        // Once deleted, it only remains in the stored rows.
        $this->main(1);
        $this->variant(2, 1);
        $this->listPrice(2, '12.0000');
        $this->projector->refresh([1], self::NOW);

        $this->connection->executeStatement('DELETE FROM tl_consho_product WHERE id IN (1, 2)');

        self::assertTrue($this->projector->refresh([1], self::NOW));
        self::assertSame([], $this->stored());
    }

    public function testRefreshReportsWhetherTheRowsChanged(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');

        self::assertFalse($this->projector->refresh([], self::NOW));
        self::assertTrue($this->projector->refresh([1], self::NOW));

        // A new timestamp alone is not a change.
        self::assertFalse($this->projector->refresh([1], self::NOW + 60));

        $this->connection->update('tl_consho_product_list_price', ['gross' => '14.0000', 'net' => '11.6667'], ['pid' => 1]);

        self::assertTrue($this->projector->refresh([1], self::NOW + 120));
        self::assertSame(['1:de/EUR H 1 (main 1) 14.0000'], $this->stored());
    }

    public function testRefreshWritesAcrossTheBlockBoundary(): void
    {
        foreach (range(1, 501) as $id) {
            $this->main($id);
            $this->listPrice($id, '12.0000');
        }

        self::assertTrue($this->projector->refresh(range(1, 501), self::NOW));
        self::assertSame(501, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM tl_consho_product_visibility WHERE language = 'de' AND variant = 0"));
    }

    public function testRebuildRemovesRowsWithoutATargetAndSetsTheMarker(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');

        // Shop 9 no longer exists; for `it` shop 1 has no root page.
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 9, 'language' => 'de', 'net' => '1.0000', 'gross' => '1.2000']);
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => self::SHOP, 'language' => 'it', 'net' => '1.0000', 'gross' => '1.2000']);

        $this->state->set(ProjectionState::VISIBILITY_SHOP_PREFIX.'9', 123);

        self::assertNull($this->state->get(ProjectionState::VISIBILITY_BOUNDARIES));

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
        self::assertSame(self::NOW, $this->state->get(ProjectionState::VISIBILITY_BOUNDARIES));
        self::assertSame(
            [ProjectionState::VISIBILITY_SHOP_PREFIX.'1' => $this->fingerprint('de/EUR,fr/EUR', 'de')],
            $this->state->withPrefix(ProjectionState::VISIBILITY_SHOP_PREFIX),
        );
    }

    public function testSyncShopsBuildsANewLanguage(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->translate(1, 'it');
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->insertRootPage(3, 'it', self::SHOP);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame([
            '1:de/EUR H 1 (main 1) 12.0000',
            '1:fr/EUR H 1 (main 1) 12.0000',
            '1:it/EUR H 1 (main 1) 12.0000',
        ], $this->stored());
    }

    public function testSyncShopsRemovesADroppedLanguage(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->connection->delete('tl_page', ['id' => 2]);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testSyncShopsRemovesADeletedShop(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->connection->delete('tl_consho_shop', ['id' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame([], $this->stored());
        self::assertSame([], $this->state->withPrefix(ProjectionState::VISIBILITY_SHOP_PREFIX));
        self::assertFalse($this->projector->syncShops(self::NOW));
    }

    public function testSyncShopsRemovesRowsOfAShopWithoutTarget(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        // Rows past the state — seed, SQL, a state from before the fingerprints.
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => 9, 'language' => 'de', 'net' => '1.0000', 'gross' => '1.2000']);

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testALanguageWithoutAVisibleProductIsNotRebuiltOnEverySync(): void
    {
        // fr has a root page but no translated product — so not a
        // single row. Read from the table, fr would be missing from the actual state
        // forever, and every save of a page would rebuild the whole shop.
        $this->main(1);
        $this->listPrice(1, '12.0000');

        self::assertTrue($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());

        self::assertFalse($this->projector->syncShops(self::NOW + 60));
        self::assertSame([self::NOW], $this->tstamps());
    }

    public function testJederStartpunktBringtSeineEigeneWaehrungMit(): void
    {
        // Spec 4.0: the currency hangs on the start point, the home country on the shop.
        // Two start points of the same shop in two currencies are thus two
        // targets with two from-prices — previously both got the same currency
        // attached from tl_consho_shop.
        $this->insertRootPage(3, 'en', self::SHOP, 'GBP');
        $this->main(1);
        $this->translate(1, 'en');
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '9.0000', 'GBP');

        $this->projector->rebuild(self::NOW);

        self::assertSame([
            '1:de/EUR H 1 (main 1) 12.0000',
            '1:en/GBP H 1 (main 1) 9.0000',
        ], $this->stored());
    }

    public function testEineWaehrungDieNurEinenStartpunktBetrifftLaesstDenAnderenStehen(): void
    {
        // The counter-check to the test above: a product that only exists in
        // euros disappears from the pound language and stays in the
        // euro language — with the shop currency as the only source it would be
        // either everywhere or nowhere.
        $this->insertRootPage(3, 'en', self::SHOP, 'GBP');
        $this->main(1);
        $this->translate(1, 'en');
        $this->listPrice(1, '12.0000');

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testDieselbeSpracheInZweiWaehrungenErgibtZweiZiele(): void
    {
        // The DACH case: example.de in euros, example.ch in francs, both in
        // German. Before task 5b the projection had (pid, shop, language) as
        // unique and no currency — the second target silently overwrote the first,
        // and the sort order decided which of the two start points won. With the
        // currency in the key there are two rows with two from-prices again.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '15.0000', 'CHF');

        $this->projector->rebuild(self::NOW);

        self::assertSame([
            '1:de/CHF H 1 (main 1) 15.0000',
            '1:de/EUR H 1 (main 1) 12.0000',
        ], $this->stored());
    }

    public function testRefreshLaesstDieZeileDerAnderenWaehrungStehen(): void
    {
        // refresh() deletes per target and writes it anew. Without the currency in
        // this deletion the euro target would also clear away the franc rows and
        // vice versa — in the end only the target written last would remain.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '15.0000', 'CHF');

        self::assertTrue($this->projector->refresh([1], self::NOW));
        self::assertSame([
            '1:de/CHF H 1 (main 1) 15.0000',
            '1:de/EUR H 1 (main 1) 12.0000',
        ], $this->stored());
    }

    public function testRefreshMerktEinenTauschDerPreiseZwischenDenWaehrungen(): void
    {
        // The fingerprint of the stored rows decides whether refresh()
        // reports a change — and with it whether the page cache is cleared.
        // Without the currency in it, "Euro 12, Franken 15" and "Euro 15,
        // Franken 12" are the same sorted list: the list would keep showing the
        // swapped prices on both pages for hours.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '15.0000', 'CHF');
        $this->projector->refresh([1], self::NOW);

        $this->connection->update('tl_consho_product_list_price', ['gross' => '15.0000', 'net' => '12.5000'], ['pid' => 1, 'currency' => 'EUR']);
        $this->connection->update('tl_consho_product_list_price', ['gross' => '12.0000', 'net' => '10.0000'], ['pid' => 1, 'currency' => 'CHF']);

        self::assertTrue($this->projector->refresh([1], self::NOW + 60));
    }

    public function testRebuildRaeumtZeilenEinerWaehrungWegDieKeinStartpunktMehrFuehrt(): void
    {
        // A start point switched from francs to euros leaves its
        // franc rows behind. For the cleanup it is not the pair of
        // shop and language that counts — that still exists — but the target
        // including its currency.
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->insertVisibility(['pid' => 1, 'main' => 1, 'variant' => 0, 'shop' => self::SHOP, 'language' => 'de', 'currency' => 'CHF', 'net' => '12.5000', 'gross' => '15.0000']);

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testEinProduktNurInEinerWaehrungStehtNurUnterDieserZeile(): void
    {
        // The counter-check: two start points of the same language, one product
        // priced only in francs. Without the currency in the key it would be visible
        // either in both rows or in none — depending on which target
        // was written last.
        $this->insertRootPage(3, 'de', self::SHOP, 'CHF');
        $this->main(1);
        $this->listPrice(1, '15.0000', 'CHF');

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/CHF H 1 (main 1) 15.0000'], $this->stored());
    }

    public function testEinStartpunktOhneSpracheErgibtKeinZiel(): void
    {
        // audiencesOf() also reports a start point without a language, so that
        // someone can name it. Whoever computes skips it — otherwise the
        // projection would hold rows with an empty language that no list ever
        // reads.
        $this->connection->insert('tl_page', ['id' => 3, 'type' => 'root', 'title' => 'Ohne Sprache', 'language' => '', 'consho_shop' => self::SHOP, 'consho_currency' => 'EUR', 'published' => 1]);
        $this->main(1);
        $this->listPrice(1, '12.0000');

        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testACurrencyChangeRebuildsTheShop(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->main(2);
        $this->listPrice(2, '12.0000');
        $this->listPrice(2, '15.0000', 'CHF');
        $this->projector->rebuild(self::NOW);

        // Since C-1c on the start point, no longer on the shop. Both start points,
        // otherwise it would be a currency change for de and none for fr.
        $this->connection->update('tl_page', ['consho_currency' => 'CHF'], ['consho_shop' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        // Product 1 has no price in CHF — and therefore no row any more.
        self::assertSame(['1:de/CHF H 2 (main 2) 15.0000'], $this->stored());
    }

    public function testEinWaehrungswechselAnEinemVonZweiStartpunktenBautDenShopNeu(): void
    {
        // The fingerprint carries the currency per language, not one per shop:
        // if it stayed a shop value, the unchanged currency of the
        // second start point would cover the change on the first, and the list
        // would keep showing euro prices.
        $this->insertRootPage(3, 'en', self::SHOP, 'GBP');
        $this->main(1);
        $this->translate(1, 'en');
        $this->listPrice(1, '12.0000');
        $this->listPrice(1, '9.0000', 'GBP');
        $this->listPrice(1, '15.0000', 'CHF');
        $this->projector->rebuild(self::NOW);

        $this->connection->update('tl_page', ['consho_currency' => 'CHF'], ['id' => 1]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        self::assertSame([
            '1:de/CHF H 1 (main 1) 15.0000',
            '1:en/GBP H 1 (main 1) 9.0000',
        ], $this->stored());
    }

    public function testEinWechselDesHeimatlandesBautDenShopNeu(): void
    {
        // The home country stays on the shop (Spec 4.0) and decides the gross of
        // every from-price — so it belongs in the fingerprint like the
        // currency, only per shop instead of per language. Until now only the
        // format assertion of the test above pinned it there; a change itself
        // was checked nowhere.
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'DE', 'net' => '10.0000', 'gross' => '11.9000']);
        $this->projector->rebuild(self::NOW);

        $this->connection->update('tl_consho_shop', ['country' => 'DE'], ['id' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        self::assertSame(['1:de/EUR H 1 (main 1) 11.9000'], $this->stored());
    }

    public function testAChangedDefaultLanguageRebuildsTheShop(): void
    {
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        $this->connection->update('tl_consho_shop', ['fallbackLanguage' => 'fr'], ['id' => self::SHOP]);

        self::assertTrue($this->projector->syncShops(self::NOW + 60));
        // Untranslated: now visible in fr, no longer in de.
        self::assertSame(['1:fr/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testSyncShopsReportsNothingWhenTheLanguagesMatch(): void
    {
        $this->main(1);
        $this->translate(1, 'fr');
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        self::assertFalse($this->projector->syncShops(self::NOW));
        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000', '1:fr/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    public function testRebuildShopUsesTheDefaultLanguageChangedInTheSameRequest(): void
    {
        // ShopLanguages caches per request. Whoever saves the shop and
        // rebuilds afterwards must not compute with the old default language.
        $this->main(1);
        $this->listPrice(1, '12.0000');
        $this->projector->rebuild(self::NOW);

        self::assertSame(['1:de/EUR H 1 (main 1) 12.0000'], $this->stored());
        self::assertSame('de', $this->languages->fallbackOf(self::SHOP));

        $this->connection->update('tl_consho_shop', ['fallbackLanguage' => 'fr'], ['id' => self::SHOP]);

        $this->projector->rebuildShop(self::SHOP, self::NOW);

        // Untranslated: now visible in fr (texts on the record), no longer in de.
        self::assertSame(['1:fr/EUR H 1 (main 1) 12.0000'], $this->stored());
    }

    /**
     * The stored rows, readable: shop:language/currency,
     * H(aupt, main)/V(ariante, variant), pid, main product, gross from-price.
     *
     * Since C-1c, task 5b, the currency is part of the key and therefore also
     * here: without it two rows of the same language in two currencies would show
     * as one entry, and the price alone would decide whether the test turns red —
     * a misspelt currency key would stay invisible.
     *
     * @return list<string>
     */
    private function stored(): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT shop, language, currency, pid, main, variant, gross FROM tl_consho_product_visibility ORDER BY shop, language, currency, pid');

        return array_map(
            static fn (array $row): string => \sprintf(
                '%d:%s/%s %s %d (main %d) %s',
                $row['shop'],
                $row['language'],
                $row['currency'],
                1 === (int) $row['variant'] ? 'V' : 'H',
                $row['pid'],
                $row['main'],
                null === $row['gross'] ? '-' : \sprintf('%.4F', $row['gross']),
            ),
            $rows,
        );
    }

    private function main(int $id): void
    {
        $this->insertProduct(['id' => $id, 'pid' => 0, 'title' => 'Produkt '.$id, 'published' => 1]);
        $this->insertProductShop(['pid' => $id, 'shop' => self::SHOP]);
    }

    private function variant(int $id, int $pid): void
    {
        $this->insertProduct(['id' => $id, 'pid' => $pid, 'title' => 'Variante '.$id, 'published' => 1]);
    }

    private function listPrice(int $productId, string $gross, string $currency = 'EUR'): void
    {
        $this->insertListPrice(['pid' => $productId, 'currency' => $currency, 'country' => 'AT', 'gross' => $gross, 'net' => \sprintf('%.4F', (float) $gross / 1.2)]);
    }

    /**
     * The fingerprint of a shop, in the format the projector writes it.
     */
    private function fingerprint(string $audiences, string $fallback, string $country = 'AT'): int
    {
        return crc32($audiences.'|'.$fallback.'|'.$country);
    }

    /**
     * @return list<int>
     */
    private function tstamps(): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn('SELECT DISTINCT tstamp FROM tl_consho_product_visibility ORDER BY tstamp'));
    }

    private function translate(int $productId, string $language): void
    {
        $this->insertTranslation(['pid' => $productId, 'ptable' => 'tl_consho_product', 'language' => $language, 'title' => 'Produit '.$productId, 'alias' => 'produit-'.$productId]);
    }
}
