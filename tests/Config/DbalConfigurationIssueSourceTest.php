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
 * 🔴 **The last finding source without a test — and the last one with a
 * static model call. That was the same fact.**
 *
 * `ShopModel::findByPk()` cannot be replaced, so up to here a test would
 * have needed a real database **and** an initialised Contao. Through
 * `$framework->getAdapter()` — as twelve other places in `src/` do — it is
 * a mock, and the test costs one SQLite file.
 *
 * The same change as in `DiagnoseTaxCommand`.
 *
 * ⚠️ **This class runs on EVERY call of the back-end start page.** Its own
 * class comment says why the expensive tax diagnosis therefore belongs in
 * `consho:tax:diagnose` and not here. The tests below pin down both: what
 * is reported, and what the source passes by.
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
     * A shop without delivery countries **and** without a home country is
     * skipped — a fresh setup would otherwise report every tax class as
     * missing and bury the findings that matter. The condition is written as
     * a comment in the class; here it is written as an assertion, so that a
     * clean-up does not remove it as superfluous.
     */
    public function testEineFrischeEinrichtungWirdUebersprungen(): void
    {
        $this->shop(1, deliveryCountries: [], country: '');

        self::assertSame([], $this->issues());
    }

    public function testEinLieferlandOhneSatzWirdGemeldet(): void
    {
        $this->shop(1, deliveryCountries: ['AT', 'DK'], country: 'AT');

        // Only AT has a rate, DK does not.
        $issues = $this->issues(configured: ['AT']);

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::TaxRateMissing, $issues[0]->finding);
    }

    /**
     * Nachlese stage 2: With the switch off, an EU private customer is charged
     * at the shop country's rate — Denmark then needs no rate of its own.
     */
    public function testOhneSteuerImBestimmungslandKeinBefundFuerEuLaender(): void
    {
        $this->shop(1, deliveryCountries: ['AT', 'DK'], country: 'AT', taxAtDestination: false);

        self::assertSame([], $this->issues(configured: ['AT']));
    }

    /**
     * 🔴 **The finding carries the shop through.**
     *
     * It was the only one of the three without it: `mailTransportMissing`
     * says "Die Shop-Konfiguration „%shop%" …", `homeCountryMissing` likewise,
     * this one only named country and product group, although the loop here
     * knows the shop. The counter-proof stood right next to it on the start
     * page: two almost identical shipping method lines can be told apart
     * **because** they name the shop.
     *
     * This test checks the source, not only `ConfigurationCheck`: that the
     * title comes from the **shop row** and not from anywhere else.
     */
    public function testDerSteuersatzBefundTraegtDenShopDurch(): void
    {
        $this->shop(1, deliveryCountries: ['DK'], country: 'AT');

        $issue = $this->issues(configured: ['AT'])[0];

        self::assertSame(1, $issue->shopId);
        self::assertSame('Testshop 1', $issue->context['shop']);
    }

    /**
     * Two shops, the same unpriced country: two lines that can be told apart.
     * Exactly the case that used to produce the same sentence twice — and
     * that an installation where only one shop delivers to Denmark never
     * shows. **That is why it is set up here.**
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
     * 🎯 **Country names, not ISO codes.** Since A-3 the message on saving the
     * shop configuration says "Dänemark"; this source said "DK" for the same
     * matter. Two wordings for one fact make an operator wonder whether they
     * are two facts.
     */
    public function testDerBefundNenntDenLandesnamen(): void
    {
        $this->shop(1, deliveryCountries: ['DK'], country: 'AT');

        $context = $this->issues(configured: ['AT'])[0]->context;

        self::assertContains('Dänemark', $context);
        self::assertNotContains('DK', $context);
    }

    /**
     * The home country counts too, even if the shop does not deliver there:
     * its rate makes the gross amount of every displayed price (C25).
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
     * 🔴 **A shop that does not exist in the model layer must not tear down
     * the start page.**
     *
     * The adapter returns `null` — before the switch to the adapter this was a
     * branch no test could reach, because the static call could not be
     * answered. Until then the line stood in the code unchecked.
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
     * "Tax in the country of destination" on, unless stated otherwise: these
     * tests date from before the switch and mean "every delivery country
     * needs its rate" — since Nachlese stage 2 that only holds this way.
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
            // Real, not mocked: a mocked incompleteFor() would invent exactly
            // the answer that is to be checked here.
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
