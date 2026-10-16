<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Command;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Intl\Countries;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoConshoBundle\Command\DiagnoseTaxCommand;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Model\TaxClassModel;
use Webwerkwien\ContaoConshoBundle\Tax\CountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\DeliveryCountryProvider;
use Webwerkwien\ContaoConshoBundle\Tax\TaxClassReferences;
use Webwerkwien\ContaoConshoBundle\Tax\TaxRateRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Tax\TaxResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Subscription\SubscriptionSchema;

/**
 * 🔴 **Dieser Test existiert, weil es ihn nicht gab.**
 *
 * Im Fremdmodell-Review vom 2026-09-23 als Regel-24-Lücke benannt: Der
 * Abschnitt über verwaiste Steuerklassen-Verweise, in A/C-12 neu gebaut, wurde
 * von keinem Test aufgerufen. Belegt war nur `TaxClassReferences::orphans()`,
 * also die Logik — nicht, dass der Befehl sie überhaupt erreicht.
 *
 * **Der Grund war nicht „schwer testbar", sondern eine ausgelassene Hausregel.**
 * Zwölf Stellen in `src/` holen Modelle über `$this->framework->getAdapter()`;
 * dieser Befehl rief `ShopModel::findByPk()` statisch. Ein statischer Aufruf
 * lässt sich nicht ersetzen — deshalb brauchte ein Test bisher Datenbank und
 * initialisiertes Framework. Über den Adapter ist er ein Mock. (Punkt 13:
 * „gibt es das schon?" kostet ein `grep` und wird trotzdem nicht gestellt.)
 *
 * ⚠️ **Gemockt wird nur, was der Befehl NICHT ist.** `DeliveryCountryProvider`,
 * `TaxResolver` und `TaxClassReferences` sind echte Exemplare über einer
 * SQLite-Datenbank und einer gemockten Satz-Schnittstelle — ein Mock von
 * `incompleteFor()` würde die Abschnitte bestätigen, die er selbst erfindet
 * (Regel 25).
 */
class DiagnoseTaxCommandTest extends TestCase
{
    private const SHOP = 1;

    private Connection $connection;

    private TaxRateRepositoryInterface&MockObject $rates;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_tax_class');
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_shipping_method');
        DcaSchema::create($this->connection, 'tl_consho_payment_method');
        DcaSchema::create($this->connection, 'tl_consho_tax_rate');
        SubscriptionSchema::create($this->connection);

        $this->connection->insert('tl_consho_tax_class', ['id' => 1, 'title' => 'Allgemeine Waren']);

        $this->rates = $this->createMock(TaxRateRepositoryInterface::class);
        $this->rates->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $this->rates->method('findConfiguredCountries')->willReturn(['AT']);
        $this->rates->method('findRate')->willReturn('20.0000');
    }

    /**
     * Review CW-6, K6: Ein Satz ohne tstamp zählt nicht (findRate). Ein
     * Import ohne tstamp hinterließe sonst eine Lücke ohne Namen. Ein
     * „neuer" Entwurf ohne Land und Sätze anderer Klassen erscheinen nicht.
     */
    public function testEntwuerfeWerdenGenannt(): void
    {
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 1, 'tstamp' => 0, 'country' => 'DE', 'rate' => '19.0000']);
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 1, 'tstamp' => 0, 'country' => '', 'rate' => '0.0000']);
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 1, 'tstamp' => 5, 'country' => 'AT', 'rate' => '20.0000']);
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 2, 'tstamp' => 0, 'country' => 'IT', 'rate' => '22.0000']);

        $display = preg_replace('/\s+/', ' ', $this->diagnose()->getDisplay()) ?? '';

        self::assertStringContainsString('Drafts that do not count', $display);
        // SQLite gibt decimal ohne Nachkommastellen zurück, MySQL mit (Fall 30).
        self::assertMatchesRegularExpression('/Drafts that do not count .* 1 DE 19(\.0+)? .*never saved/', $display);
        self::assertDoesNotMatchRegularExpression('/Drafts that do not count .* AT .*never saved/', $display, 'der gespeicherte Satz ist kein Entwurf');
        self::assertStringNotContainsString(' IT ', $display, 'eine Klasse, die der Shop nicht nutzt');
        // Zweiter Review CW-6, S2: die Zeile ohne Land (1, leer, 0) fehlt.
        self::assertDoesNotMatchRegularExpression('/Drafts that do not count .* 1 0(\.0+)? .*never saved/', $display, 'ein „neuer" Entwurf ohne Land');
    }

    public function testOhneEntwurfKeinAbschnitt(): void
    {
        self::assertStringNotContainsString('Drafts', $this->diagnose()->getDisplay());
    }

    public function testOhneVerwaisteVerweiseBleibtDerAbschnittWeg(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Wanderstock', 'taxClass' => 1]);

        $tester = $this->diagnose();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('does not exist', $tester->getDisplay());
    }

    /**
     * 🎯 **Die Zusicherung, um die es geht.** Am 2026-09-23 auf c5 von Hand
     * hergestellt — Versandart löschen, Steuerklasse löschen, Versandart aus
     * dem Papierkorb zurückholen — und der Abschnitt erschien. Das war ein
     * Beleg für jenen Stand; hier ist er einer für jeden nächsten.
     */
    public function testEinVerwaisterVerweisStehtImBericht(): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'ZZ Probe Verwaist', 'taxClass' => 99]);

        $tester = $this->diagnose();
        $display = $tester->getDisplay();

        self::assertSame(0, $tester->getStatusCode(), 'Ein Diagnosebefehl meldet, er scheitert nicht.');
        self::assertStringContainsString('Pointing at a tax class that does not exist', $display);
        self::assertStringContainsString('ZZ Probe Verwaist', $display);
        self::assertStringContainsString('tl_consho_shipping_method', $display);
        self::assertStringContainsString('99', $display);
        self::assertStringContainsString('cannot be priced', $display);
        // Gegenprobe: ohne Abo kein Abo-Satz.
        self::assertStringNotContainsString('cannot be billed again', $display);
    }

    /**
     * Review Abo-3, L3: Das Abo ist schreibgeschützt und zeigt keinen Preis —
     * „Datensatz öffnen" hilft dort nicht, der Schaden ist die nächste Rechnung.
     */
    public function testEinAboMitVerwaisterKlasseBekommtSeinenEigenenSatz(): void
    {
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 99]);

        $display = $this->diagnose()->getDisplay();

        self::assertStringContainsString('WEB-00090-1', $display);
        self::assertStringContainsString('cannot be billed again', $display);
    }

    /**
     * Ein Produkt ohne jede Zuordnung gehört genauso in den Bericht — der
     * Schaden ist derselbe, und über das Backend kann es gar nicht entstehen
     * (Pflichtfeld), also kommt es aus Import oder SQL.
     */
    public function testAuchEineLeereZuordnungStehtImBericht(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Ohne Klasse', 'taxClass' => 0]);

        self::assertStringContainsString('Ohne Klasse', $this->diagnose()->getDisplay());
    }

    /**
     * 🔴 **Der Weg, auf dem der Abschnitt NICHT kommt.**
     *
     * `--class/--country` kehrt vor dem Bericht zurück. Das ist richtig — die
     * Frage lautet dort „welcher Satz gilt hier", nicht „was ist kaputt" —,
     * aber es ist der Grund, warum ein flüchtiger Blick den Abschnitt für
     * gebaut halten könnte, wo er nie läuft. Festgehalten, damit ein späteres
     * Aufräumen die frühe Rückkehr nicht versehentlich entfernt.
     */
    public function testDerDirekteWegUeberEineKlasseBerichtetNichtUeberVerwaiste(): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'ZZ Probe Verwaist', 'taxClass' => 99]);

        $tester = $this->diagnose(['--class' => '1', '--country' => 'AT']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('ZZ Probe Verwaist', $tester->getDisplay());
    }

    /**
     * Die Meldung nennt die Shops, die es gibt — sonst bleibt nur, IDs
     * durchzuprobieren (Michael, 2026-10-12: nicht raten lassen).
     */
    public function testEinUnbekannterShopScheitertMitMeldung(): void
    {
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->insert('tl_consho_shop', ['id' => 4, 'title' => 'I7 Shop']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Testshop 2 &#40;DE&#41;']);

        $tester = $this->diagnose(shop: null);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('No shop configuration with ID '.self::SHOP.'. Shop configurations: 2 (Testshop 2 (DE)), 4 (I7 Shop).', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    public function testEinUnlesbaresDatumNenntDieForm(): void
    {
        $tester = new CommandTester($this->command(self::SHOP));
        $tester->execute(['shop' => (string) self::SHOP, 'date' => 'gestern irgendwann']);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('Cannot read that date. Give it like 2020-09-01, or "now".', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    /**
     * @param array<string, string> $options
     */
    private function diagnose(array $options = [], int|null $shop = self::SHOP): CommandTester
    {
        $tester = new CommandTester($this->command($shop));
        $tester->execute(['shop' => (string) self::SHOP, 'date' => '2026-09-23', ...$options]);

        return $tester;
    }

    private function command(int|null $shop): DiagnoseTaxCommand
    {
        $countries = $this->createMock(Countries::class);
        $countries->method('getCountryCodes')->willReturn(['AT', 'DE']);
        $countries->method('getCountries')->willReturn(['AT' => 'Österreich', 'DE' => 'Deutschland']);

        $provider = new CountryProvider($countries);

        return new DiagnoseTaxCommand(
            $this->framework($shop),
            $this->rates,
            new DeliveryCountryProvider($this->rates, $provider),
            new TaxResolver($this->rates, $provider),
            new TaxClassReferences($this->connection),
            $this->connection,
        );
    }

    private function framework(int|null $shop): ContaoFramework
    {
        $shopModel = null;

        if (null !== $shop) {
            $shopModel = $this->createMock(ShopModel::class);
            $shopModel->method('__get')->willReturnMap([
                ['id', $shop],
                ['title', 'Testshop'],
                ['country', 'AT'],
                ['deliveryCountries', serialize(['AT'])],
            ]);
        }

        $class = $this->createMock(TaxClassModel::class);
        $class->method('__get')->willReturnMap([['id', 1], ['title', 'Allgemeine Waren']]);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturn($shopModel);

        $classAdapter = $this->createMock(Adapter::class);
        $classAdapter->method('__call')->willReturn($class);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [ShopModel::class, $shopAdapter],
            [TaxClassModel::class, $classAdapter],
        ]);

        return $framework;
    }
}
