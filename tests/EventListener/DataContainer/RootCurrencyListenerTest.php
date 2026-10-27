<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\RootCurrencyListener;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Against SQLite instead of a mock of ShopCurrencies: the service is final
 * and reads by itself, and exactly its result — sorted, deduplicated,
 * without a silent default — decides visibility, options and rejection here.
 */
class RootCurrencyListenerTest extends TestCase
{
    /**
     * Built like the real palette according to contao/dca/tl_page.php: the
     * currency comes after the shop, not before. That is not decoration — when
     * saving, DC_Table processes the palette fields in order.
     */
    private const PALETTE = '{title_legend},title,alias,type;{consho_legend},consho_shop,consho_currency,consho_cartPage;{publish_legend},published';

    private Connection $connection;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'rootCurrencyNotAllowed' => 'nicht erlaubt: %1$s im Shop %2$s',
            'rootCurrencyWithoutShop' => 'kein Shop, keine Währung: %1$s',
        ];

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Only the two columns that ShopCurrencies reads — as in
        // ShopCurrenciesTest.
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']['CONSHO']);
    }

    /**
     * Case 1: a shop with exactly one currency offers no choice. The field
     * disappears from the palette, and the value still arrives — otherwise the
     * root page would stand there without a currency, because no save callback
     * runs for a field outside the palette either.
     */
    public function testEineEinzigeWaehrungBlendetDasFeldAusUndSetztDenWertBeimSpeichern(): void
    {
        $this->insertShop(1, serialize(['CHF']));

        self::assertStringNotContainsString(
            'consho_currency',
            $this->listener()->adjustPalette(self::PALETTE, $this->dc(7, 1)),
        );

        $values = $this->listener()->onBeforeSubmit(['consho_shop' => 1], $this->dc(7, 1));

        self::assertSame('CHF', $values['consho_currency']);
    }

    /**
     * Case 2: several currencies are a choice, so there is a field — and in it
     * exactly the currencies of this shop, sorted. Not the whole ISO list: that
     * is on the shop, because that is where it is decided what is possible.
     */
    public function testMehrereWaehrungenZeigenDasFeldMitGenauDiesenOptionen(): void
    {
        $this->insertShop(1, serialize(['USD', 'EUR', 'CHF']));

        self::assertStringContainsString(
            'consho_currency',
            $this->listener()->adjustPalette(self::PALETTE, $this->dc(7, 1)),
        );

        self::assertSame(
            ['CHF' => 'CHF', 'EUR' => 'EUR', 'USD' => 'USD'],
            $this->listener()->options($this->dc(7, 1)),
        );
    }

    /**
     * Case 3: the actual bolt. The option list alone stops nothing — Contao
     * appends a stored value outside the options again as "Unknown option" and
     * takes it back on save (SelectMenu::generate()). The message names the
     * currency, otherwise the editor looks for it in a list in which it no
     * longer appears.
     */
    public function testEineNichtMehrErlaubteWaehrungWirdBeimSpeichernAbgelehnt(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        // The message also names the shop: whoever runs several configurations
        // can make nothing of "the shop of this root page".
        $this->expectExceptionMessage('nicht erlaubt: USD im Shop Shop 1');

        $this->listener()->save('USD', $this->dc(7, 1, 'USD'));
    }

    /**
     * The write path of the visible field. Without this test a `return '';`
     * survived the whole suite — and on every save of a multi-currency root
     * page silently emptied the currency the editor had just chosen. Nobody
     * would set it again: with two or more currencies, `resolve()` explicitly
     * does not choose.
     */
    public function testEineErlaubteWaehrungWirdDurchgereicht(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame('EUR', $this->listener()->save('EUR', $this->dc(7, 1)));
    }

    /**
     * Empty passes. An empty mandatory field does not reach the save callback
     * at all — the branch only counts once the field loses its mandatory flag.
     * Without it an empty input would be rejected as an unknown currency.
     */
    public function testEinLeererWertWirdNichtAlsUnbekannteWaehrungAbgelehnt(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame('', $this->listener()->save('', $this->dc(7, 1)));
    }

    /**
     * The deviation from the model `saveCurrency()`, which does not normalise:
     * the migration from step 3 writes this column directly, never through a
     * form. `ShopCurrencies` upper-cases the shop side for exactly this reason
     * — without the same treatment here a taken-over "eur" would be rejected as
     * unknown, although the shop accepts EUR.
     */
    public function testEinKleingeschriebenerCodeWirdGrossGeschriebenStattAbgelehnt(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame('EUR', $this->listener()->save(' eur ', $this->dc(7, 1)));
    }

    /**
     * Contao calls an options_callback even without a record, for instance for
     * the filter above the page list. Without a root page there is no shop and
     * so nothing to offer — but no error either.
     */
    public function testOhneDatensatzGibtEsKeineOptionenUndKeinenFehler(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame([], $this->listener()->options());
    }

    /**
     * Via "edit multiple → override" there is no palette at all
     * (`DC_Table::overrideAll()` does not call `getPalette()`), so the currency
     * can also land on a page **without** a shop. Then the message must not
     * claim a shop that does not exist.
     */
    public function testOhneShopNenntDieMeldungKeinenShop(): void
    {
        $this->expectExceptionMessage('kein Shop, keine Währung: EUR');

        $this->listener()->save('EUR', $this->dc(7, 0));
    }

    /**
     * The reason for the field order, and the only test that can catch it: the
     * field callbacks must see the shop **just chosen**, not the last saved one.
     * On `getCurrentRecord()` this test falls over, on `getActiveRecord()` it
     * stands.
     */
    public function testDieOptionenFolgenDemGeradeGewaehltenShop(): void
    {
        $this->insertShop(1, serialize(['EUR']));
        $this->insertShop(2, serialize(['USD', 'GBP']));

        self::assertSame(
            ['GBP' => 'GBP', 'USD' => 'USD'],
            $this->listener()->options($this->dc(7, 1, 'EUR', 2)),
        );
    }

    /**
     * The same mix-up on the write path: the old currency EUR belongs to the
     * old shop 1 and is no longer allowed in the shop 2 just chosen. Measured
     * against the saved record it would pass — and the root page would stand
     * there with a currency its shop does not accept.
     */
    public function testDasSpeichernPrueftGegenDenGeradeGewaehltenShop(): void
    {
        $this->insertShop(1, serialize(['EUR']));
        $this->insertShop(2, serialize(['USD', 'GBP']));

        $this->expectExceptionMessage('nicht erlaubt: EUR im Shop Shop 2');

        $this->listener()->save('EUR', $this->dc(7, 1, 'EUR', 2));
    }

    /**
     * Case 4: a root page without a shop configuration has no currency that
     * would apply anywhere. The field is then not empty but not there at all.
     */
    public function testEinStartpunktOhneShopHatDasFeldNicht(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertStringNotContainsString(
            'consho_currency',
            $this->listener()->adjustPalette(self::PALETTE, $this->dc(7, 0)),
        );
    }

    /**
     * Without a record no shop is known, so no currency applies, so no field —
     * the same direction as everywhere else in the class. An abort guard as in
     * `tl_files::adjustPalettes()` would have done the opposite: show where the
     * least is known.
     */
    public function testOhneDatensatzVerschwindetDasFeld(): void
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('getCurrentRecord')->willReturn(null);

        self::assertStringNotContainsString('consho_currency', $this->listener()->adjustPalette(self::PALETTE, $dc));
    }

    /**
     * The callback hangs on tl_page and therefore runs when **any** page is
     * saved. An ordinary subpage carries no shop and may therefore stay
     * untouched — even without a single query.
     *
     * ⚠️ The missing table is the actual touchstone, not scenery: without the
     * early exit ShopCurrencies would query the database for shop 0 as well and
     * run into an error here. With the table the test would be toothless — the
     * result would be the same on both paths (measured).
     */
    public function testEineSeiteOhneShopWirdNichtAngefasst(): void
    {
        $this->connection->executeStatement('DROP TABLE tl_consho_shop');

        self::assertSame(['title' => 'Impressum'], $this->listener()->onBeforeSubmit(['title' => 'Impressum'], $this->dc(7, 0)));
    }

    /**
     * If the root page loses its shop, its currency no longer applies either.
     * Otherwise it would remain as an invisible leftover: without a shop the
     * field is hidden, nobody would ever see the value again.
     */
    public function testMitDemShopFaelltAuchDieWaehrung(): void
    {
        $this->insertShop(1, serialize(['CHF']));

        $values = $this->listener()->onBeforeSubmit(['consho_shop' => 0], $this->dc(7, 1, 'CHF'));

        self::assertSame('', $values['consho_currency']);
    }

    /**
     * The stored value is read, not only the submitted one: a change to any
     * other field must not lose a valid currency.
     */
    public function testEineGespeicherteWaehrungUeberlebtEineFremdeAenderung(): void
    {
        $this->insertShop(1, serialize(['EUR', 'CHF']));

        self::assertSame(['title' => 'Start'], $this->listener()->onBeforeSubmit(['title' => 'Start'], $this->dc(7, 1, 'CHF')));
    }

    /**
     * `valueOf()` takes an array. Without the fallback to `[]` a missing record
     * would not be an empty value but a type error in the middle of the save.
     */
    public function testOhneDatensatzScheitertDasSpeichernNicht(): void
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('getCurrentRecord')->willReturn(null);

        self::assertSame(['title' => 'Start'], $this->listener()->onBeforeSubmit(['title' => 'Start'], $dc));
    }

    private function listener(): RootCurrencyListener
    {
        return new RootCurrencyListener(new ShopCurrencies($this->connection));
    }

    /**
     * ⚠️ The double must distinguish what the original distinguishes.
     *
     * `getCurrentRecord()` is the **stored** record, `getActiveRecord()` the
     * same record overlaid with what has been submitted so far
     * (`DC_Table::getActiveRecord()` = `array_merge(getCurrentRecord(),
     * arrSubmit)`). If both returned the same, the whole test class would be
     * blind to the mix-up — and exactly that mix-up is the reason why
     * `consho_shop` comes before `consho_currency` in the palette.
     *
     * `$submittedShop` is the shop just chosen in the form, not yet saved.
     * Without it both are the same: that is what a call looks like in which the
     * shop was not touched.
     */
    private function dc(int $id, int $shopId, string $currency = '', int|null $submittedShop = null): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id]]);

        $stored = ['id' => $id, 'consho_shop' => $shopId, 'consho_currency' => $currency];

        $dc->method('getCurrentRecord')->willReturn($stored);
        $dc->method('getActiveRecord')->willReturn(['consho_shop' => $submittedShop ?? $shopId] + $stored);

        return $dc;
    }

    private function insertShop(int $id, string|null $currencies): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => $id, 'title' => 'Shop '.$id, 'currencies' => $currencies]);
    }
}
