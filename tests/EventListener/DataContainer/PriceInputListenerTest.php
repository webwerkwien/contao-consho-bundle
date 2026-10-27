<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Intl\Countries;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CoupledPriceInputListener;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceInputListener;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalMarketRepository;
use Webwerkwien\ContaoConshoBundle\Pricing\GrossCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\HomeCountries;
use Webwerkwien\ContaoConshoBundle\Pricing\Market;
use Webwerkwien\ContaoConshoBundle\Pricing\MarketRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceEntry;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Catalog\CatalogSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

class PriceInputListenerTest extends TestCase
{
    use CatalogSchema;
    use TaxFixture;

    private const NOW = 1_800_000_000;

    private const FUTURE = self::NOW + 86_400;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = ['priceCurrencyWithoutShop' => 'keine Shop-Währung: %s'];
        $GLOBALS['TL_LANG']['CONSHO']['errors']['priceCountryMissing'] = 'Land für %1$s aus %2$s wählen';
        $GLOBALS['TL_LANG']['CONSHO']['errors']['priceTaxClassMissing'] = 'keine Steuerklasse';
        $GLOBALS['TL_LANG']['CONSHO']['errors']['priceTaxRateMissing'] = 'kein Satz in %s am %s';
        $GLOBALS['TL_LANG']['CONSHO']['errors']['priceNegative'] = 'negativ';
        $GLOBALS['TL_LANG']['CONSHO']['errors']['priceOverrideNeedsMode'] = 'mit Eingabeart';
        $GLOBALS['TL_LANG']['CONSHO']['errors']['priceTooLarge'] = 'zu groß';
        $GLOBALS['TL_LANG']['CONSHO']['errors']['priceAmountMissing'] = 'Betrag fehlt';
        $GLOBALS['TL_DCA']['tl_consho_price'] = [
            'palettes' => ['default' => '{price_legend},currency,inputCountry,inputMode,amount;{scope_legend},customerGroup,minQuantity'],
            'fields' => ['currency' => [], 'inputCountry' => [], 'inputMode' => [], 'amount' => [], 'customerGroup' => [], 'minQuantity' => []],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_LANG'], $GLOBALS['TL_DCA']);
    }

    public function testNurWaehrungenAngelegterShopsStehenZurWahl(): void
    {
        $listener = $this->listener([['EUR', 'AT'], ['CHF', 'CH'], ['EUR', 'DE']]);

        self::assertSame(['CHF' => 'CHF', 'EUR' => 'EUR'], $listener->currencyOptions());
    }

    public function testJederCallbackDerPreismaskeIstGenauEinmalRegistriert(): void
    {
        // Pins the move into a base class: no callback lost, none doubled, none
        // on a foreign table, none with a swapped target.
        $targets = [];

        foreach ((new \ReflectionClass(PriceInputListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                self::assertSame(PriceInputListener::class, $method->getDeclaringClass()->getName(), $method->getName());

                $arguments = $attribute->getArguments();
                $targets[] = $arguments['table'].' '.$arguments['target'].' '.$method->getName();
            }
        }

        sort($targets);

        self::assertSame([
            'tl_consho_price config.onbeforesubmit onBeforeSubmit',
            'tl_consho_price config.oncopy onCopy',
            'tl_consho_price config.onload onLoad',
            'tl_consho_price fields.amount.load loadAmount',
            'tl_consho_price fields.currency.options currencyOptions',
            'tl_consho_price fields.currency.save saveCurrency',
            'tl_consho_price fields.inputCountry.options countryOptions',
            'tl_consho_price fields.inputMode.load loadInputMode',
        ], $targets);
    }

    public function testDieBasisklasseRegistriertSelbstKeinenCallback(): void
    {
        // Otherwise every subclass would additionally attach its callbacks to the
        // base's table — getMethods() returns inherited methods along with their attributes.
        $class = new \ReflectionClass(CoupledPriceInputListener::class);

        self::assertTrue($class->isAbstract());

        foreach ($class->getMethods() as $method) {
            self::assertSame([], $method->getAttributes(AsCallback::class), $method->getName());
        }
    }

    public function testBeiNurEinerWaehrungIstSieVorbelegt(): void
    {
        $this->listener([['EUR', 'AT']])->onLoad();

        self::assertSame('EUR', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['currency']['default']);
    }

    public function testBeiMehrerenWaehrungenIstKeineVorbelegt(): void
    {
        $this->listener([['EUR', 'AT'], ['CHF', 'CH']])->onLoad();

        // Empty, not missing: otherwise the column default EUR would take effect.
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['currency']['default']);
    }

    public function testDasLandIstAusgeblendetWennJedeWaehrungNurEinHeimatlandHat(): void
    {
        // The normal case: shops from one country. The operator only sees
        // "gross | net" and the amount.
        $this->listener([['EUR', 'AT'], ['CHF', 'CH']])->onLoad();

        $palette = $GLOBALS['TL_DCA']['tl_consho_price']['palettes']['default'];

        self::assertStringNotContainsString('inputCountry', $palette);
        self::assertStringContainsString('inputMode', $palette);
    }

    public function testDasLandBleibtSichtbarWennEineWaehrungZweiHeimatlaenderHat(): void
    {
        $this->listener([['EUR', 'AT'], ['EUR', 'DE']])->onLoad();

        self::assertStringContainsString('inputCountry', $GLOBALS['TL_DCA']['tl_consho_price']['palettes']['default']);
    }

    public function testDasLandBietetImmerAlleHeimatlaender(): void
    {
        // Without automatic submit the list cannot follow a currency that has
        // just been chosen — PriceInputListener checks that on save.
        $listener = $this->listener([['EUR', 'DE'], ['CHF', 'CH'], ['EUR', 'AT']]);

        self::assertSame(['AT' => 'Österreich', 'CH' => 'Schweiz', 'DE' => 'Deutschland'], $listener->countryOptions($this->dc(['currency' => 'EUR'])));
    }

    public function testOhneDatensatzBietetDasLandAlleHeimatlaender(): void
    {
        $listener = $this->listener([['EUR', 'DE'], ['CHF', 'CH'], ['EUR', 'AT']]);

        self::assertSame(['AT' => 'Österreich', 'CH' => 'Schweiz', 'DE' => 'Deutschland'], $listener->countryOptions());
    }

    public function testImMehrfachBearbeitenOhneDatensatzBietetDasLandAlleHeimatlaender(): void
    {
        // "Edit multiple → override": getCurrentRecord() returns null.
        $listener = $this->listener([['EUR', 'DE'], ['CHF', 'CH'], ['EUR', 'AT']]);

        self::assertSame(['AT' => 'Österreich', 'CH' => 'Schweiz', 'DE' => 'Deutschland'], $listener->countryOptions($this->dc(null)));
    }

    public function testAuchEineWaehrungOhneShopBietetAlleHeimatlaender(): void
    {
        $listener = $this->listener([['EUR', 'DE'], ['CHF', 'CH'], ['EUR', 'AT']]);

        self::assertSame(['AT' => 'Österreich', 'CH' => 'Schweiz', 'DE' => 'Deutschland'], $listener->countryOptions($this->dc(['currency' => 'XYZ'])));
    }

    public function testOhneShopGibtEsKeineWaehrungUndKeinLandfeld(): void
    {
        $listener = $this->listener([]);

        $listener->onLoad();

        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['currency']['default']);
        self::assertStringNotContainsString('inputCountry', $GLOBALS['TL_DCA']['tl_consho_price']['palettes']['default']);
        self::assertSame([], $listener->currencyOptions());
    }

    public function testEineWaehrungOhneShopWirdBeimSpeichernAbgelehnt(): void
    {
        $listener = $this->listener([['EUR', 'AT']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('keine Shop-Währung: XYZ');

        $listener->saveCurrency('XYZ');
    }

    public function testEineWaehrungEinesShopsWirdGespeichert(): void
    {
        $listener = $this->listener([['EUR', 'AT']]);

        self::assertSame('EUR', $listener->saveCurrency('EUR'));
    }

    public function testEineLeereWaehrungUeberlaesstDasDemPflichtfeld(): void
    {
        $listener = $this->listener([['EUR', 'AT']]);

        self::assertSame('', $listener->saveCurrency(''));
    }

    public function testEineWaehrungEinesUnveroeffentlichtenStartpunktsWirdAngenommen(): void
    {
        // 🎯 This is how a currency is prepared before anybody sees it: create
        // the root page, choose GBP, maintain prices, then publish.
        //
        // Against the real database instead of the market mock of the other
        // tests, and that is the whole point: the question is not what the form
        // does with a list of markets, but whether DbalMarketRepository makes the
        // unpublished root page a market at all. A mock never answers that — it
        // returns what we write into it.
        // If the query picked up a `published` filter, saveCurrency() would throw
        // here, and the currency could only be maintained once it is already
        // online.
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($connection);
        $this->createRootPageTable($connection);

        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'country' => 'AT']);
        $connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'language' => 'en', 'consho_shop' => 1, 'consho_currency' => 'GBP', 'published' => 0]);

        $listener = new PriceInputListener(
            new HomeCountries(new DbalMarketRepository($connection)),
            new CountryProvider($this->createMock(Countries::class)),
            $connection,
            new PriceEntry($this->grossCalculator()),
            new RequestStack(),
        );

        self::assertSame(['GBP' => 'GBP'], $listener->currencyOptions());
        self::assertSame('GBP', $listener->saveCurrency('GBP'));
    }

    // ── On saving ───────────────────────────────────────────────────────

    public function testEineBruttoEingabeWirdBeimSpeichernZuNetto(): void
    {
        $values = $this->listener()->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'inputCountry' => ''], $this->dc());

        self::assertSame('10.0000', $values['net']);
        self::assertSame('gross', $values['inputMode']);
        // Only one home country for EUR: set, although the field was hidden.
        self::assertSame('AT', $values['inputCountry']);
    }

    public function testEinNieGespeicherterDatensatzBleibtBeimAutomatischenAbsendenNeu(): void
    {
        // No field of this form submits automatically; the protection stays for
        // the case that one gets submitOnChange: submit() has already set
        // tstamp. If it stayed, "Back" would no longer delete the row, and it
        // would be in the database with net 0.
        // Without `amount`: on automatic submit Contao skips the empty mandatory
        // field, so it is missing from the values. The reset must therefore come
        // BEFORE the early return for a missing `amount`.
        $values = $this->listener([['EUR', 'AT']], 1, 'auto')->onBeforeSubmit(
            ['tstamp' => self::NOW, 'currency' => 'EUR', 'inputMode' => 'gross'],
            $this->dc(['tstamp' => 0, 'net' => '0.0000']),
        );

        self::assertSame(0, $values['tstamp']);
    }

    public function testEinGespeicherterDatensatzBehaeltBeimAutomatischenAbsendenSeinenZeitstempel(): void
    {
        // Protection for a possible future submitOnChange: a submit with a real
        // tstamp must not reset it to 0.
        $values = $this->listener([['EUR', 'AT']], 1, 'auto')->onBeforeSubmit(
            ['tstamp' => self::NOW, 'currency' => 'EUR', 'inputMode' => 'gross', 'amount' => '12.00'],
            $this->dc(),
        );

        self::assertSame(self::NOW, $values['tstamp']);
    }

    public function testDerBetragLandetNieInDerDatenbank(): void
    {
        // DC_Table::submit() writes every key — amount has no column.
        $values = $this->listener()->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross'], $this->dc());

        self::assertArrayNotHasKey('amount', $values);
    }

    public function testEineNettoEingabeWirdOhneSatzGespeichert(): void
    {
        // Tax class 0 and no home country for CHF — irrelevant for net.
        $values = $this->listener([['EUR', 'AT']], 0)->onBeforeSubmit(['amount' => '10.5', 'inputMode' => 'net', 'currency' => 'CHF'], $this->dc());

        self::assertSame('10.5000', $values['net']);
        self::assertSame('net', $values['inputMode']);
        self::assertSame('', $values['inputCountry']);
    }

    public function testBeimAutomatischenAbsendenWirdNichtUmgerechnet(): void
    {
        // Protection for a possible future submitOnChange: an automatic submit
        // must not convert — the amount may be empty, the country may still
        // belong to the old currency.
        $values = $this->listener([['EUR', 'AT']], 1, 'auto')->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'currency' => 'EUR'], $this->dc());

        self::assertArrayNotHasKey('net', $values);
        self::assertArrayNotHasKey('amount', $values);
    }

    public function testEinNegativerBetragWirdAbgelehnt(): void
    {
        // rgxp digit lets -1 through, and Contao does not check 'minval' => 0.
        $this->expectExceptionMessage('negativ');

        $this->listener()->onBeforeSubmit(['amount' => '-1.00', 'inputMode' => 'net'], $this->dc());
    }

    public function testEinZuGrosserNettobetragWirdAbgelehnt(): void
    {
        // net is decimal(12,4), eight digits before the point. Contao's maxval
        // would miss here: it checks before the comma conversion.
        $this->expectExceptionMessage('zu groß');

        $this->listener()->onBeforeSubmit(['amount' => '123456789.5', 'inputMode' => 'net'], $this->dc());
    }

    public function testEinBruttobetragZaehltMitSeinemNetto(): void
    {
        // Gross above the net limit, but the net calculated from it still fits
        // — 20 % tax rate in AT, the only home for EUR.
        $values = $this->listener()->onBeforeSubmit(['amount' => '99999999.99', 'inputMode' => 'gross'], $this->dc());

        self::assertSame('83333333.3250', $values['net']);
    }

    public function testDerGroessteNettobetragPasstNochHinein(): void
    {
        $values = $this->listener()->onBeforeSubmit(['amount' => '99999999.9999', 'inputMode' => 'net'], $this->dc());

        self::assertSame('99999999.9999', $values['net']);
    }

    public function testEinZuGrosserBruttobetragWirdAbgelehnt(): void
    {
        $this->expectExceptionMessage('zu groß');

        $this->listener()->onBeforeSubmit(['amount' => '150000000.00', 'inputMode' => 'gross'], $this->dc());
    }

    public function testMitGewaehltemLandRechnetDieMaskeMitDessenSatz(): void
    {
        $values = $this->listener([['EUR', 'AT'], ['EUR', 'DE']])->onBeforeSubmit(['amount' => '11.90', 'inputMode' => 'gross', 'inputCountry' => 'DE'], $this->dc());

        self::assertSame('10.0000', $values['net']);
    }

    public function testBeiZweiHeimatlaendernOhneWahlLehntDieMaskeAb(): void
    {
        $this->expectExceptionMessage('Land für EUR aus Österreich, Deutschland wählen');

        $this->listener([['EUR', 'AT'], ['EUR', 'DE']])->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'inputCountry' => ''], $this->dc());
    }

    public function testEinLandEinerAnderenWaehrungWirdBeimSpeichernAbgelehnt(): void
    {
        // The country list now always shows all home countries — whether the
        // chosen one matches the currency is only checked by onBeforeSubmit().
        $this->expectExceptionMessage('Land für EUR aus Österreich, Deutschland wählen');

        $this->listener([['EUR', 'AT'], ['EUR', 'DE'], ['CHF', 'CH']])->onBeforeSubmit(
            ['amount' => '12.00', 'inputMode' => 'gross', 'currency' => 'EUR', 'inputCountry' => 'CH'],
            $this->dc(),
        );
    }

    public function testOhneSteuerklasseLehntDieMaskeEinBruttoAb(): void
    {
        $this->expectExceptionMessage('keine Steuerklasse');

        $this->listener([['EUR', 'AT']], 0)->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross'], $this->dc());
    }

    public function testOhneSatzImBezugslandLehntDieMaskeAb(): void
    {
        // The calculator knows AT and DE, the shop sells in CHF from Switzerland.
        $this->expectExceptionMessage('kein Satz in Schweiz am');

        $this->listener([['CHF', 'CH']])->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'currency' => 'CHF'], $this->dc(['currency' => 'CHF']));
    }

    public function testEinPreisDerSpaeterBeginntRechnetMitDemSatzAbSeinemBeginn(): void
    {
        // Announced rate change: 21 % from FUTURE. Whoever creates the new price
        // today already means the gross from its start.
        $listener = $this->listener([['EUR', 'AT']], 1, null, $this->calculatorChangingAt(self::FUTURE));

        $future = $listener->onBeforeSubmit(['amount' => '12.10', 'inputMode' => 'gross', 'validFrom' => self::FUTURE], $this->dc());
        $today = $listener->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'validFrom' => null], $this->dc());

        self::assertSame('10.0000', $future['net']);
        self::assertSame('10.0000', $today['net']);
    }

    public function testEinGeleertesGueltigAbRechnetMitDemHeutigenSatz(): void
    {
        // validFrom arrives emptied as null in the values. With `??` it would
        // fall back to the stored start and calculate with 21 %.
        $listener = $this->listener([['EUR', 'AT']], 1, null, $this->calculatorChangingAt(self::FUTURE));

        $values = $listener->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'validFrom' => null], $this->dc(['validFrom' => self::FUTURE]));

        self::assertSame('10.0000', $values['net']);
    }

    public function testBeimWaehrungswechselFolgtDasLandDerNeuenWaehrung(): void
    {
        // Currency switched, the country of the old currency was still set:
        // saved with the country and rate of the new one.
        $values = $this->listener([['EUR', 'AT'], ['CHF', 'CH']], 1, null, $this->grossCalculator(['AT' => '20.0000', 'CH' => '8.1000']))->onBeforeSubmit(
            ['currency' => 'CHF', 'inputCountry' => 'AT', 'inputMode' => 'gross', 'amount' => '10.81'],
            $this->dc(),
        );

        self::assertSame('CH', $values['inputCountry']);
        // 10.81 / 1.081 = exactly 10.
        self::assertSame('10.0000', $values['net']);
    }

    public function testDerSchutzFuerAutomatischesAbsendenRechnetNichtUm(): void
    {
        // No field of this form submits automatically; the protection stays for
        // the case that one gets submitOnChange.
        $values = $this->listener([['EUR', 'AT'], ['CHF', 'CH']], 1, 'auto')->onBeforeSubmit(
            ['currency' => 'CHF', 'inputCountry' => 'AT', 'inputMode' => 'gross', 'amount' => '12.00'],
            $this->dc(),
        );

        self::assertSame('CH', $values['inputCountry']);
        self::assertArrayNotHasKey('net', $values);
    }

    public function testEinLandOhnePassendeWahlWirdBeimAutomatischenAbsendenGeleert(): void
    {
        // Protection for a possible future submitOnChange: EUR has two home
        // countries, neither of them chosen — the country of the old currency
        // (CHF: CH) must not remain.
        $values = $this->listener([['EUR', 'AT'], ['EUR', 'DE'], ['CHF', 'CH']], 1, 'auto')->onBeforeSubmit(
            ['currency' => 'EUR', 'inputCountry' => 'CH', 'inputMode' => 'gross'],
            $this->dc(['currency' => 'CHF', 'inputCountry' => 'CH']),
        );

        self::assertSame('', $values['inputCountry']);
    }

    public function testImUeberschreibenBrauchtDerBetragDieEingabeart(): void
    {
        // "Edit multiple → override": the amount without "Input as" would be
        // net in a net row and gross in a gross row.
        $this->expectExceptionMessage('mit Eingabeart');

        $this->listener([['EUR', 'AT']], 1, null, null, ['act' => 'overrideAll'])->onBeforeSubmit(['amount' => '12.00'], $this->dc());
    }

    public function testEineNeueZeileOhneBetragWirdNichtAlsPreisGespeichert(): void
    {
        // A user group without permission for "Amount" only sees "Currency" —
        // without the check the new row would be live with net 0.
        $this->expectExceptionMessage('Betrag fehlt');

        $this->listener()->onBeforeSubmit(
            ['currency' => 'EUR', 'tstamp' => self::NOW],
            $this->dc(['tstamp' => 0, 'net' => '0.0000']),
        );
    }

    public function testEineDuplizierteZeileOhneBetragWirdGespeichert(): void
    {
        // DC_Table::copy(): tstamp 0, but the net of the original — a real
        // price, not an empty new row. The block only applies at net 0.
        $values = $this->listener()->onBeforeSubmit(['currency' => 'EUR'], $this->dc(['tstamp' => 0, 'net' => '10.4167']));

        self::assertArrayNotHasKey('net', $values);
    }

    public function testEineGespeicherteZeileOhneBetragBleibtUnveraendert(): void
    {
        // Already saved (tstamp > 0): no new price of 0, so no rejection — only
        // "Amount" is missing from the values, not the whole row.
        $values = $this->listener()->onBeforeSubmit(['currency' => 'EUR'], $this->dc());

        self::assertArrayNotHasKey('net', $values);
    }

    // ── On opening ──────────────────────────────────────────────────────

    public function testEineZeileOhneEingabeartOeffnetAlsNetto(): void
    {
        // Restored from a version before C-1b: Versions::restore() empties later
        // columns — the mandatory radio choice "inputMode" would otherwise show
        // nothing selected.
        $listener = $this->listener();

        self::assertSame('net', $listener->loadInputMode(''));
        self::assertSame('gross', $listener->loadInputMode('gross'));
        self::assertSame('net', $listener->loadInputMode(null));
    }

    public function testEineBruttoZeileZeigtDasBruttoAusNetto(): void
    {
        $amount = $this->listener()->loadAmount(null, $this->dc(['net' => '16.6583', 'inputMode' => 'gross', 'inputCountry' => 'AT']));

        // To the cent, as typed — PriceEntry::toAmount().
        self::assertSame('19.99', $amount);
    }

    /**
     * Like the other decimal fields of the form (DecimalFieldListener): with
     * the separator of the backend language. Seen on a real installation — the
     * minimum quantity already showed "1", the amount next to it still
     * "10.4167".
     */
    public function testDerBetragSprichtDieSpracheDesBackends(): void
    {
        $GLOBALS['TL_LANG']['MSC']['decimalSeparator'] = ',';

        try {
            self::assertSame('19,99', $this->listener()->loadAmount(null, $this->dc(['net' => '16.6583', 'inputMode' => 'gross', 'inputCountry' => 'AT'])));
            self::assertSame('10,4167', $this->listener()->loadAmount(null, $this->dc(['net' => '10.4167'])));
        } finally {
            unset($GLOBALS['TL_LANG']['MSC']['decimalSeparator']);
        }
    }

    public function testEineAlteZeileZeigtIhrNetto(): void
    {
        // Saved before C-1b: inputMode has the default value net.
        self::assertSame('10.4167', $this->listener()->loadAmount(null, $this->dc(['net' => '10.4167'])));
    }

    public function testEinNeuerDatensatzZeigtEinLeeresBetragsfeld(): void
    {
        // Freshly created, never saved: tstamp 0, net 0 — "0.0000" in the
        // field would look like a price.
        self::assertSame('', $this->listener()->loadAmount(null, $this->dc(['tstamp' => 0, 'net' => '0.0000', 'inputMode' => 'gross'])));
    }

    public function testEineDuplizierteEinzelnePreiszeileZeigtIhrenBetrag(): void
    {
        // DC_Table::copy() gives the copy tstamp 0, but the net of the original —
        // whoever duplicates a price for another customer group does not retype it.
        self::assertSame('10.4167', $this->listener()->loadAmount(null, $this->dc(['tstamp' => 0, 'net' => '10.4167'])));
    }

    public function testOhneSatzZeigtDieMaskeEinLeeresBetragsfeld(): void
    {
        // Do not show the net: on save it would be read as gross.
        $amount = $this->listener([['CHF', 'CH']])->loadAmount(null, $this->dc(['currency' => 'CHF', 'inputMode' => 'gross', 'inputCountry' => 'CH']));

        self::assertSame('', $amount);
    }

    public function testEinPreisDerSpaeterBeginntZeigtBeimOeffnenDasBruttoAbSeinemBeginn(): void
    {
        // Announced rate change: 21 % from FUTURE. As long as the price does not
        // apply yet, the form shows the gross at its own rate — otherwise an
        // unchanged save would deviate from the net shown.
        $listener = $this->listener([['EUR', 'AT']], 1, null, $this->calculatorChangingAt(self::FUTURE));

        $amount = $listener->loadAmount(null, $this->dc(['net' => '10.0000', 'inputMode' => 'gross', 'inputCountry' => 'AT', 'validFrom' => self::FUTURE]));

        self::assertSame('12.10', $amount);
    }

    public function testOhneSteuerklasseZeigtEineBruttoZeileEinLeeresBetragsfeld(): void
    {
        // Tax class 0: without it the net cannot be shown as gross.
        $amount = $this->listener([['EUR', 'AT']], 0)->loadAmount(null, $this->dc(['inputMode' => 'gross', 'inputCountry' => 'AT']));

        self::assertSame('', $amount);
    }

    public function testBeiZweiHeimatlaendernOhneGespeichertesLandZeigtEineBruttoZeileEinLeeresBetragsfeld(): void
    {
        // Two home countries for EUR, no country stored — resolve() returns
        // none, and without a country no rate can be determined.
        $amount = $this->listener([['EUR', 'AT'], ['EUR', 'DE']])->loadAmount(null, $this->dc(['inputMode' => 'gross', 'inputCountry' => '']));

        self::assertSame('', $amount);
    }

    private function calculatorChangingAt(int $from): GrossCalculator
    {
        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findRate')->willReturnCallback(
            static fn (int $taxClassId, string $country, int $at): string => $at >= $from ? '21.0000' : '20.0000',
        );

        return new GrossCalculator(new TaxResolver($rates, new CountryProvider($this->createMock(Countries::class))));
    }

    /**
     * @param list<array{string, string}> $markets currency, country
     * @param array<string, string> $query GET parameters, e.g. `act`
     */
    private function listener(array $markets = [['EUR', 'AT']], int $taxClass = 1, string|null $submitType = null, GrossCalculator|null $calculator = null, array $query = []): PriceInputListener
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->method('findMarkets')->willReturn(array_map(static fn (array $m): Market => new Market($m[0], $m[1]), $markets));

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'CH' => 'Schweiz', 'DE' => 'Deutschland']);

        // The tax class of the product the price row hangs on — all test
        // records hang on pid 1.
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->with(self::anything(), [1])->willReturn($taxClass);

        $requests = new RequestStack();
        $requests->push(new Request($query, null === $submitType ? [] : ['SUBMIT_TYPE' => $submitType]));

        return new PriceInputListener(
            new HomeCountries($repository),
            new CountryProvider($countries),
            $connection,
            new PriceEntry($calculator ?? $this->grossCalculator(['AT' => '20.0000', 'DE' => '19.0000'])),
            $requests,
            static fn (): int => self::NOW,
        );
    }

    /**
     * A record as from getCurrentRecord(): an old net row in euros.
     *
     * @param array<string, mixed>|null $overrides null = no record
     */
    private function dc(array|null $overrides = []): DataContainer
    {
        $record = null === $overrides ? null : array_merge([
            'id' => 9,
            'pid' => 1,
            'tstamp' => 5,
            'currency' => 'EUR',
            'net' => '10.0000',
            'inputMode' => 'net',
            'inputCountry' => '',
            'validFrom' => null,
        ], $overrides);

        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn($record);

        return $dc;
    }
}
