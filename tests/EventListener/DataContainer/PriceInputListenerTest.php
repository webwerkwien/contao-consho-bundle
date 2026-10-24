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
        // Hält den Umbau in eine Basisklasse fest: kein Callback verloren,
        // keiner doppelt, keiner an einer fremden Tabelle, keiner mit vertauschtem Ziel.
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
        // Sonst hinge jede Unterklasse ihre Callbacks zusätzlich an die Tabelle
        // der Basis — getMethods() liefert geerbte Methoden samt Attributen.
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

        // Leer, nicht fehlend: sonst griffe der Spalten-Standard EUR.
        self::assertSame('', $GLOBALS['TL_DCA']['tl_consho_price']['fields']['currency']['default']);
    }

    public function testDasLandIstAusgeblendetWennJedeWaehrungNurEinHeimatlandHat(): void
    {
        // Der Normalfall: Shops aus einem Land. Der Betreiber sieht nur
        // „brutto | netto" und den Betrag.
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
        // Ohne automatisches Absenden kann die Liste einer eben gewählten
        // Währung nicht folgen — PriceInputListener prüft das beim Speichern.
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
        // "Mehrere bearbeiten → überschreiben": getCurrentRecord() liefert null.
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
        // 🎯 So wird eine Währung vorbereitet, bevor sie jemand sieht: Startpunkt
        // anlegen, GBP wählen, Preise pflegen, dann veröffentlichen.
        //
        // Gegen die echte Datenbank statt gegen den Markt-Mock der übrigen
        // Tests, und das ist der ganze Zweck: Die Frage ist nicht, was die Maske
        // mit einer Liste von Märkten tut, sondern ob DbalMarketRepository den
        // unveröffentlichten Startpunkt überhaupt zum Markt macht. Ein Mock
        // beantwortet das nie — er gibt zurück, was wir hineinschreiben.
        // Nähme die Abfrage einen `published`-Filter auf, würfe saveCurrency()
        // hier, und die Währung ließe sich erst pflegen, wenn sie schon online
        // ist.
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

    // ── Beim Speichern ──────────────────────────────────────────────────

    public function testEineBruttoEingabeWirdBeimSpeichernZuNetto(): void
    {
        $values = $this->listener()->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'inputCountry' => ''], $this->dc());

        self::assertSame('10.0000', $values['net']);
        self::assertSame('gross', $values['inputMode']);
        // Nur ein Heimatland für EUR: gesetzt, obwohl das Feld ausgeblendet war.
        self::assertSame('AT', $values['inputCountry']);
    }

    public function testEinNieGespeicherterDatensatzBleibtBeimAutomatischenAbsendenNeu(): void
    {
        // Kein Feld dieser Maske sendet automatisch ab; der Schutz bleibt für
        // den Fall, dass eines submitOnChange bekommt: submit() hat tstamp
        // schon gesetzt. Bliebe er, löschte „Zurück" die Zeile nicht mehr, und
        // sie stünde mit Netto 0 in der Datenbank.
        // Ohne `amount`: Beim automatischen Absenden überspringt Contao das
        // leere Pflichtfeld, es fehlt in den Werten. Die Zurücksetzung muss
        // deshalb VOR dem frühen Return für fehlendes `amount` stehen.
        $values = $this->listener([['EUR', 'AT']], 1, 'auto')->onBeforeSubmit(
            ['tstamp' => self::NOW, 'currency' => 'EUR', 'inputMode' => 'gross'],
            $this->dc(['tstamp' => 0, 'net' => '0.0000']),
        );

        self::assertSame(0, $values['tstamp']);
    }

    public function testEinGespeicherterDatensatzBehaeltBeimAutomatischenAbsendenSeinenZeitstempel(): void
    {
        // Schutz für ein mögliches künftiges submitOnChange: ein Absenden mit
        // echtem tstamp darf ihn nicht auf 0 zurücksetzen.
        $values = $this->listener([['EUR', 'AT']], 1, 'auto')->onBeforeSubmit(
            ['tstamp' => self::NOW, 'currency' => 'EUR', 'inputMode' => 'gross', 'amount' => '12.00'],
            $this->dc(),
        );

        self::assertSame(self::NOW, $values['tstamp']);
    }

    public function testDerBetragLandetNieInDerDatenbank(): void
    {
        // DC_Table::submit() schreibt jeden Schlüssel — amount hat keine Spalte.
        $values = $this->listener()->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross'], $this->dc());

        self::assertArrayNotHasKey('amount', $values);
    }

    public function testEineNettoEingabeWirdOhneSatzGespeichert(): void
    {
        // Steuerklasse 0 und kein Heimatland für CHF — für netto egal.
        $values = $this->listener([['EUR', 'AT']], 0)->onBeforeSubmit(['amount' => '10.5', 'inputMode' => 'net', 'currency' => 'CHF'], $this->dc());

        self::assertSame('10.5000', $values['net']);
        self::assertSame('net', $values['inputMode']);
        self::assertSame('', $values['inputCountry']);
    }

    public function testBeimAutomatischenAbsendenWirdNichtUmgerechnet(): void
    {
        // Schutz für ein mögliches künftiges submitOnChange: ein automatisches
        // Absenden darf nicht umrechnen — der Betrag kann leer sein, das Land
        // noch zur alten Währung gehören.
        $values = $this->listener([['EUR', 'AT']], 1, 'auto')->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'currency' => 'EUR'], $this->dc());

        self::assertArrayNotHasKey('net', $values);
        self::assertArrayNotHasKey('amount', $values);
    }

    public function testEinNegativerBetragWirdAbgelehnt(): void
    {
        // rgxp digit lässt -1 durch, und 'minval' => 0 prüft Contao nicht.
        $this->expectExceptionMessage('negativ');

        $this->listener()->onBeforeSubmit(['amount' => '-1.00', 'inputMode' => 'net'], $this->dc());
    }

    public function testEinZuGrosserNettobetragWirdAbgelehnt(): void
    {
        // net ist decimal(12,4), acht Vorkommastellen. Contaos maxval liefe hier
        // ins Leere: es prüft vor der Komma-Umwandlung.
        $this->expectExceptionMessage('zu groß');

        $this->listener()->onBeforeSubmit(['amount' => '123456789.5', 'inputMode' => 'net'], $this->dc());
    }

    public function testEinBruttobetragZaehltMitSeinemNetto(): void
    {
        // Brutto über der Netto-Grenze, das daraus errechnete Netto passt aber
        // noch — 20 % Steuersatz in AT, der einzigen Heimat für EUR.
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
        // Die Länderliste zeigt jetzt immer alle Heimatländer — ob das gewählte
        // zur Währung passt, prüft erst onBeforeSubmit().
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
        // Der Rechner kennt AT und DE, der Shop verkauft in CHF aus der Schweiz.
        $this->expectExceptionMessage('kein Satz in Schweiz am');

        $this->listener([['CHF', 'CH']])->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'currency' => 'CHF'], $this->dc(['currency' => 'CHF']));
    }

    public function testEinPreisDerSpaeterBeginntRechnetMitDemSatzAbSeinemBeginn(): void
    {
        // Angekündigte Satzänderung: ab FUTURE 21 %. Wer den neuen Preis
        // schon heute anlegt, meint das Brutto ab seinem Beginn.
        $listener = $this->listener([['EUR', 'AT']], 1, null, $this->calculatorChangingAt(self::FUTURE));

        $future = $listener->onBeforeSubmit(['amount' => '12.10', 'inputMode' => 'gross', 'validFrom' => self::FUTURE], $this->dc());
        $today = $listener->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'validFrom' => null], $this->dc());

        self::assertSame('10.0000', $future['net']);
        self::assertSame('10.0000', $today['net']);
    }

    public function testEinGeleertesGueltigAbRechnetMitDemHeutigenSatz(): void
    {
        // validFrom kommt geleert als null in den Werten an. Mit `??` fiele es
        // auf den gespeicherten Beginn zurück und rechnete mit 21 %.
        $listener = $this->listener([['EUR', 'AT']], 1, null, $this->calculatorChangingAt(self::FUTURE));

        $values = $listener->onBeforeSubmit(['amount' => '12.00', 'inputMode' => 'gross', 'validFrom' => null], $this->dc(['validFrom' => self::FUTURE]));

        self::assertSame('10.0000', $values['net']);
    }

    public function testBeimWaehrungswechselFolgtDasLandDerNeuenWaehrung(): void
    {
        // Währung gewechselt, das Land der alten Währung war noch gesetzt:
        // gespeichert wird mit dem Land und Satz der neuen.
        $values = $this->listener([['EUR', 'AT'], ['CHF', 'CH']], 1, null, $this->grossCalculator(['AT' => '20.0000', 'CH' => '8.1000']))->onBeforeSubmit(
            ['currency' => 'CHF', 'inputCountry' => 'AT', 'inputMode' => 'gross', 'amount' => '10.81'],
            $this->dc(),
        );

        self::assertSame('CH', $values['inputCountry']);
        // 10.81 / 1.081 = 10 genau.
        self::assertSame('10.0000', $values['net']);
    }

    public function testDerSchutzFuerAutomatischesAbsendenRechnetNichtUm(): void
    {
        // Kein Feld dieser Maske sendet automatisch ab; der Schutz bleibt für
        // den Fall, dass eines submitOnChange bekommt.
        $values = $this->listener([['EUR', 'AT'], ['CHF', 'CH']], 1, 'auto')->onBeforeSubmit(
            ['currency' => 'CHF', 'inputCountry' => 'AT', 'inputMode' => 'gross', 'amount' => '12.00'],
            $this->dc(),
        );

        self::assertSame('CH', $values['inputCountry']);
        self::assertArrayNotHasKey('net', $values);
    }

    public function testEinLandOhnePassendeWahlWirdBeimAutomatischenAbsendenGeleert(): void
    {
        // Schutz für ein mögliches künftiges submitOnChange: EUR hat zwei
        // Heimatländer, keines davon gewählt — das Land der alten Währung
        // (CHF: CH) darf nicht stehen bleiben.
        $values = $this->listener([['EUR', 'AT'], ['EUR', 'DE'], ['CHF', 'CH']], 1, 'auto')->onBeforeSubmit(
            ['currency' => 'EUR', 'inputCountry' => 'CH', 'inputMode' => 'gross'],
            $this->dc(['currency' => 'CHF', 'inputCountry' => 'CH']),
        );

        self::assertSame('', $values['inputCountry']);
    }

    public function testImUeberschreibenBrauchtDerBetragDieEingabeart(): void
    {
        // „Mehrere bearbeiten → überschreiben": der Betrag ohne "Eingabe als"
        // wäre in einer Nettozeile netto, in einer Bruttozeile brutto.
        $this->expectExceptionMessage('mit Eingabeart');

        $this->listener([['EUR', 'AT']], 1, null, null, ['act' => 'overrideAll'])->onBeforeSubmit(['amount' => '12.00'], $this->dc());
    }

    public function testEineNeueZeileOhneBetragWirdNichtAlsPreisGespeichert(): void
    {
        // Eine Benutzergruppe ohne Recht auf "Betrag" sieht nur "Währung" —
        // ohne die Prüfung stünde die neue Zeile mit Netto 0 live.
        $this->expectExceptionMessage('Betrag fehlt');

        $this->listener()->onBeforeSubmit(
            ['currency' => 'EUR', 'tstamp' => self::NOW],
            $this->dc(['tstamp' => 0, 'net' => '0.0000']),
        );
    }

    public function testEineDuplizierteZeileOhneBetragWirdGespeichert(): void
    {
        // DC_Table::copy(): tstamp 0, aber das Netto des Originals — ein echter
        // Preis, keine leere neue Zeile. Die Sperre gilt nur bei Netto 0.
        $values = $this->listener()->onBeforeSubmit(['currency' => 'EUR'], $this->dc(['tstamp' => 0, 'net' => '10.4167']));

        self::assertArrayNotHasKey('net', $values);
    }

    public function testEineGespeicherteZeileOhneBetragBleibtUnveraendert(): void
    {
        // Bereits gespeichert (tstamp > 0): kein neuer Preis von 0, also keine
        // Ablehnung — nur „Betrag" fehlt in den Werten, nicht die ganze Zeile.
        $values = $this->listener()->onBeforeSubmit(['currency' => 'EUR'], $this->dc());

        self::assertArrayNotHasKey('net', $values);
    }

    // ── Beim Öffnen ─────────────────────────────────────────────────────

    public function testEineZeileOhneEingabeartOeffnetAlsNetto(): void
    {
        // Aus einer Version vor C-1b wiederhergestellt: Versions::restore()
        // setzt spätere Spalten leer — die Pflicht-Radio-Auswahl "inputMode"
        // zeigte sonst nichts Gewähltes.
        $listener = $this->listener();

        self::assertSame('net', $listener->loadInputMode(''));
        self::assertSame('gross', $listener->loadInputMode('gross'));
        self::assertSame('net', $listener->loadInputMode(null));
    }

    public function testEineBruttoZeileZeigtDasBruttoAusNetto(): void
    {
        $amount = $this->listener()->loadAmount(null, $this->dc(['net' => '16.6583', 'inputMode' => 'gross', 'inputCountry' => 'AT']));

        // Auf den Cent, wie eingetippt — PriceEntry::toAmount() (Nachtrag Task 1).
        self::assertSame('19.99', $amount);
    }

    /**
     * Wie die übrigen Dezimalfelder der Maske (DecimalFieldListener): mit dem
     * Trennzeichen der Backend-Sprache, gesehen auf c5 am 2026-10-24 — die
     * Mindestmenge zeigte schon „1“, der Betrag daneben noch „10.4167“.
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
        // Vor C-1b gespeichert: inputMode hat den Standardwert net.
        self::assertSame('10.4167', $this->listener()->loadAmount(null, $this->dc(['net' => '10.4167'])));
    }

    public function testEinNeuerDatensatzZeigtEinLeeresBetragsfeld(): void
    {
        // Frisch angelegt, noch nie gespeichert: tstamp 0, net 0 — "0.0000"
        // im Feld sähe aus wie ein Preis.
        self::assertSame('', $this->listener()->loadAmount(null, $this->dc(['tstamp' => 0, 'net' => '0.0000', 'inputMode' => 'gross'])));
    }

    public function testEineDuplizierteEinzelnePreiszeileZeigtIhrenBetrag(): void
    {
        // DC_Table::copy() gibt der Kopie tstamp 0, aber das Netto des Originals —
        // wer einen Preis für eine andere Kundengruppe dupliziert, tippt ihn nicht neu.
        self::assertSame('10.4167', $this->listener()->loadAmount(null, $this->dc(['tstamp' => 0, 'net' => '10.4167'])));
    }

    public function testOhneSatzZeigtDieMaskeEinLeeresBetragsfeld(): void
    {
        // Nicht das Netto zeigen: beim Speichern würde es als Brutto gelesen.
        $amount = $this->listener([['CHF', 'CH']])->loadAmount(null, $this->dc(['currency' => 'CHF', 'inputMode' => 'gross', 'inputCountry' => 'CH']));

        self::assertSame('', $amount);
    }

    public function testEinPreisDerSpaeterBeginntZeigtBeimOeffnenDasBruttoAbSeinemBeginn(): void
    {
        // Angekündigte Satzänderung: ab FUTURE 21 %. Solange der Preis noch
        // nicht gilt, zeigt die Maske das Brutto zu seinem eigenen Satz —
        // sonst wiche ein ungeändertes Speichern vom gezeigten Netto ab.
        $listener = $this->listener([['EUR', 'AT']], 1, null, $this->calculatorChangingAt(self::FUTURE));

        $amount = $listener->loadAmount(null, $this->dc(['net' => '10.0000', 'inputMode' => 'gross', 'inputCountry' => 'AT', 'validFrom' => self::FUTURE]));

        self::assertSame('12.10', $amount);
    }

    public function testOhneSteuerklasseZeigtEineBruttoZeileEinLeeresBetragsfeld(): void
    {
        // Steuerklasse 0: ohne sie lässt sich das Netto nicht in brutto zeigen.
        $amount = $this->listener([['EUR', 'AT']], 0)->loadAmount(null, $this->dc(['inputMode' => 'gross', 'inputCountry' => 'AT']));

        self::assertSame('', $amount);
    }

    public function testBeiZweiHeimatlaendernOhneGespeichertesLandZeigtEineBruttoZeileEinLeeresBetragsfeld(): void
    {
        // Zwei Heimatländer für EUR, kein Land gespeichert — resolve() liefert
        // keins, und ohne Land lässt sich kein Satz bestimmen.
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
     * @param array<string, string>       $query   GET-Parameter, z. B. `act`
     */
    private function listener(array $markets = [['EUR', 'AT']], int $taxClass = 1, string|null $submitType = null, GrossCalculator|null $calculator = null, array $query = []): PriceInputListener
    {
        $repository = $this->createMock(MarketRepositoryInterface::class);
        $repository->method('findMarkets')->willReturn(array_map(static fn (array $m): Market => new Market($m[0], $m[1]), $markets));

        $countries = $this->createMock(Countries::class);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'CH' => 'Schweiz', 'DE' => 'Deutschland']);

        // Die Steuerklasse des Produkts, an dem die Preiszeile hängt — alle
        // Testdatensätze hängen an pid 1.
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
     * Ein Datensatz wie aus getCurrentRecord(): eine alte Nettozeile in Euro.
     *
     * @param array<string, mixed>|null $overrides null = kein Datensatz
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
