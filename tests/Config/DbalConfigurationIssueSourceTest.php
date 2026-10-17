<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Config;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Countries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationCheck;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\DbalConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\DeliveryCountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * 🔴 **Die letzte Befundquelle ohne Test — und die letzte mit einem statischen
 * Modellaufruf. Das war dieselbe Tatsache.**
 *
 * `ShopModel::findByPk()` lässt sich nicht ersetzen, also hätte ein Test bis
 * hierher eine echte Datenbank **und** ein initialisiertes Contao gebraucht.
 * Über `$framework->getAdapter()` — was zwölf andere Stellen in `src/` tun —
 * ist er ein Mock, und der Test kostet eine SQLite-Datei.
 *
 * Nachgezogen am 2026-09-23, direkt nach demselben Umbau an
 * `DiagnoseTaxCommand`. Punkt 31: **Ein Vorfall, der nur eine Reparatur
 * hervorbringt, ist halb ausgewertet.**
 *
 * ⚠️ **Diese Klasse läuft bei JEDEM Aufruf der Backend-Startseite.** Ihr
 * eigener Klassenkommentar sagt, warum die teure Steuerdiagnose deshalb in
 * `consho:tax:diagnose` gehört und nicht hierher. Die Tests unten halten
 * beides fest: was gemeldet wird, und woran die Quelle vorbeigeht.
 */
class DbalConfigurationIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_tax_class');
        $this->connection->insert('tl_consho_tax_class', ['id' => 1, 'title' => 'Allgemeine Waren']);
    }

    public function testOhneShopGibtEsNichtsZuMelden(): void
    {
        self::assertSame([], $this->issues());
    }

    /**
     * Ein Shop ohne Lieferländer **und** ohne Heimatland wird übersprungen —
     * eine frische Einrichtung würde sonst jede Steuerklasse als fehlend
     * melden und die Befunde begraben, auf die es ankommt. Die Bedingung steht
     * als Kommentar in der Klasse; hier steht sie als Zusicherung, damit ein
     * Aufräumen sie nicht als überflüssig entfernt.
     */
    public function testEineFrischeEinrichtungWirdUebersprungen(): void
    {
        $this->shop(1, deliveryCountries: [], country: '');

        self::assertSame([], $this->issues());
    }

    public function testEinLieferlandOhneSatzWirdGemeldet(): void
    {
        $this->shop(1, deliveryCountries: ['AT', 'DK'], country: 'AT');

        // Nur AT hat einen Satz, DK nicht.
        $issues = $this->issues(configured: ['AT']);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::TaxRateMissing, $issues[0]->finding);
    }

    /**
     * Nachlese Stufe 2: Mit dem Schalter aus rechnet ein EU-Privatkunde zum
     * Satz des Shoplands — Dänemark braucht dann keinen eigenen.
     */
    public function testOhneSteuerImBestimmungslandKeinBefundFuerEuLaender(): void
    {
        $this->shop(1, deliveryCountries: ['AT', 'DK'], country: 'AT', taxAtDestination: false);

        self::assertSame([], $this->issues(configured: ['AT']));
    }

    /**
     * 🔴 **Der Befund trägt den Shop durch — seit 2026-09-23.**
     *
     * Er war als Einziger der drei ohne: `mailTransportMissing` sagt „Die
     * Shop-Konfiguration „%shop%" …", `homeCountryMissing` ebenso, dieser
     * nannte nur Land und Warengruppe, obwohl die Schleife hier den Shop
     * kennt. Auf der Startseite stand der Gegenbeweis direkt daneben: zwei
     * fast gleiche Versandart-Zeilen sind auseinanderzuhalten, **weil** sie
     * den Shop nennen.
     *
     * Dieser Test prüft die Quelle, nicht nur `ConfigurationCheck`: dass der
     * Titel aus der **Shop-Zeile** kommt und nicht irgendwoher.
     */
    public function testDerSteuersatzBefundTraegtDenShopDurch(): void
    {
        $this->shop(1, deliveryCountries: ['DK'], country: 'AT');

        $issue = $this->issues(configured: ['AT'])[0];

        self::assertSame(1, $issue->shopId);
        self::assertSame('Testshop 1', $issue->context['shop']);
    }

    /**
     * Zwei Shops, dasselbe unbepreiste Land: zwei Zeilen, die sich
     * unterscheiden lassen. Genau der Fall, der vorher zweimal denselben Satz
     * ergab — und der auf c5 nie auftrat, weil nur ein Shop nach Dänemark
     * liefert. **Deshalb steht er hier und nicht nur dort.**
     */
    public function testZweiShopsMitDemselbenLueckenlandSindUnterscheidbar(): void
    {
        $this->shop(1, deliveryCountries: ['DK'], country: 'AT');
        $this->shop(2, deliveryCountries: ['DK'], country: 'AT');

        $issues = $this->issues(configured: ['AT']);

        self::assertCount(2, $issues);
        self::assertSame(['Testshop 1', 'Testshop 2'], [$issues[0]->context['shop'], $issues[1]->context['shop']]);
    }

    /**
     * 🎯 **Ländernamen, keine ISO-Kürzel.** Die Meldung beim Speichern der
     * Shop-Konfiguration sagt seit A-3 „Dänemark"; diese Quelle sagte „DK" für
     * denselben Sachverhalt. Zwei Formulierungen für eine Tatsache lassen
     * einen Betreiber überlegen, ob es zwei Tatsachen sind.
     */
    public function testDerBefundNenntDenLandesnamen(): void
    {
        $this->shop(1, deliveryCountries: ['DK'], country: 'AT');

        $context = $this->issues(configured: ['AT'])[0]->context;

        self::assertContains('Dänemark', $context);
        self::assertNotContains('DK', $context);
    }

    /**
     * Das Heimatland zählt mit, auch wenn der Shop nicht dorthin liefert:
     * Sein Satz macht das Brutto jedes angezeigten Preises (C25).
     */
    public function testDasHeimatlandZaehltAuchOhneLieferung(): void
    {
        $this->shop(1, deliveryCountries: ['AT'], country: 'DE');

        $issues = $this->issues(configured: ['AT']);

        self::assertCount(1, $issues);
        self::assertContains('Deutschland', $issues[0]->context);
    }

    public function testZweiShopsMitDerselbenUidWerdenGemeldet(): void
    {
        $this->shop(1, deliveryCountries: [], country: '', vatId: 'ATU12345678');
        $this->shop(2, deliveryCountries: [], country: '', vatId: 'ATU 12345678');

        $issues = $this->issues();

        self::assertCount(1, $issues, 'Die Schreibweise mit Leerzeichen ist dieselbe UID.');
        self::assertSame(ConfigurationFinding::DuplicateVatId, $issues[0]->finding);
    }

    /**
     * 🔴 **Ein Shop, den es in der Modellschicht nicht gibt, darf die
     * Startseite nicht abreißen.**
     *
     * Der Adapter gibt `null` zurück — bis zum Umbau war das ein Zweig, den
     * kein Test erreichen konnte, weil der statische Aufruf sich nicht
     * beantworten ließ. Die Zeile steht seither ungeprüft im Code.
     */
    public function testEinShopOhneModellUeberspringtDieSteuerpruefung(): void
    {
        $this->shop(1, deliveryCountries: ['AT'], country: 'AT');

        self::assertSame([], $this->issues(configured: [], modelExists: false));
    }

    /**
     * @param list<string> $deliveryCountries
     */
    /**
     * „Steuer im Bestimmungsland" an, wenn nichts anderes gesagt ist: Diese
     * Tests stammen aus der Zeit vor dem Schalter und meinen „jedes Lieferland
     * braucht seinen Satz" — seit der Nachlese Stufe 2 gilt das nur so.
     */
    private function shop(int $id, array $deliveryCountries, string $country, string $vatId = '', bool $taxAtDestination = true): void
    {
        $this->connection->insert('tl_consho_shop', [
            'id' => $id,
            'title' => 'Testshop '.$id,
            'vatId' => $vatId,
            'deliveryCountries' => serialize($deliveryCountries),
            'country' => $country,
            'taxAtDestination' => $taxAtDestination ? 1 : 0,
        ]);
    }

    /**
     * @param list<string> $configured countries that DO have a rate
     *
     * @return list<\Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue>
     */
    private function issues(array $configured = [], bool $modelExists = true): array
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE', 'DK']);
        $countries->method('getCountries')->willReturn([
            'AT' => 'Österreich',
            'DE' => 'Deutschland',
            'DK' => 'Dänemark',
        ]);

        $provider = new CountryProvider($countries);

        $rates = $this->createMock(TaxRateRepositoryInterface::class);
        $rates->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $rates->method('findConfiguredCountries')->willReturn($configured);

        return (new DbalConfigurationIssueSource(
            $this->connection,
            new ConfigurationCheck(),
            // Echt, nicht gemockt: Ein gemocktes incompleteFor() würde genau
            // die Antwort erfinden, die hier geprüft werden soll (Regel 25).
            new DeliveryCountryProvider($rates, $provider),
            $provider,
            $this->framework($modelExists),
        ))->issues();
    }

    private function framework(bool $modelExists): ContaoFramework
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->willReturnCallback(
            function (string $method, array $args) use ($modelExists): ShopModel|null {
                if (!$modelExists) {
                    return null;
                }

                $shop = $this->createMock(ShopModel::class);
                $shop->method('__get')->willReturnMap([
                    ['id', $args[0]],
                    ['title', 'Testshop '.$args[0]],
                    ['country', (string) $this->connection->fetchOne('SELECT country FROM tl_consho_shop WHERE id = ?', [$args[0]])],
                    ['deliveryCountries', (string) $this->connection->fetchOne('SELECT deliveryCountries FROM tl_consho_shop WHERE id = ?', [$args[0]])],
                    ['taxAtDestination', (string) $this->connection->fetchOne('SELECT taxAtDestination FROM tl_consho_shop WHERE id = ?', [$args[0]])],
                ]);

                return $shop;
            },
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($adapter);

        return $framework;
    }
}
