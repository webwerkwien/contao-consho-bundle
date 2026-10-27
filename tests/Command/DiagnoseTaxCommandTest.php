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
 * 🔴 **This test exists because it did not exist.**
 *
 * The section on orphaned tax class references, newly built in A/C-12,
 * was called by no test. Only `TaxClassReferences::orphans()` was
 * covered, i.e. the logic — not that the command reaches it at all.
 *
 * **The reason was not "hard to test", but a house rule that was not
 * followed.** Twelve places in `src/` fetch models via
 * `$this->framework->getAdapter()`; this command called
 * `ShopModel::findByPk()` statically. A static call cannot be replaced —
 * which is why a test so far needed a database and an initialised
 * framework. Through the adapter it is a mock. (Asking "does this already
 * exist?" costs one `grep`.)
 *
 * ⚠️ **Only what the command is NOT gets mocked.** `DeliveryCountryProvider`,
 * `TaxResolver` and `TaxClassReferences` are real instances over an
 * SQLite database and a mocked rate interface — a mock of
 * `incompleteFor()` would confirm the sections it invents itself.
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
        DcaSchema::create($this->connection, 'tl_consho_voucher');
        SubscriptionSchema::create($this->connection);

        $this->connection->insert('tl_consho_tax_class', ['id' => 1, 'title' => 'Allgemeine Waren']);

        $this->rates = $this->createMock(TaxRateRepositoryInterface::class);
        $this->rates->method('findTaxClassIdsUsedByShop')->willReturn([1]);
        $this->rates->method('findConfiguredCountries')->willReturn(['AT']);
        $this->rates->method('findRate')->willReturn('20.0000');
    }

    /**
     * CW-6: A rate without a tstamp does not count (findRate). An
     * import without a tstamp would otherwise leave a gap without a name. A
     * "new" draft without a country and rates of other classes do not appear.
     */
    public function testEntwuerfeWerdenGenannt(): void
    {
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 1, 'tstamp' => 0, 'country' => 'DE', 'rate' => '19.0000']);
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 1, 'tstamp' => 0, 'country' => '', 'rate' => '0.0000']);
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 1, 'tstamp' => 5, 'country' => 'AT', 'rate' => '20.0000']);
        $this->connection->insert('tl_consho_tax_rate', ['pid' => 2, 'tstamp' => 0, 'country' => 'IT', 'rate' => '22.0000']);

        $display = preg_replace('/\s+/', ' ', $this->diagnose()->getDisplay()) ?? '';

        self::assertStringContainsString('Drafts that do not count', $display);
        // SQLite returns decimal without decimal places, MySQL with them.
        self::assertMatchesRegularExpression('/Drafts that do not count .* 1 DE 19(\.0+)? .*never saved/', $display);
        self::assertDoesNotMatchRegularExpression('/Drafts that do not count .* AT .*never saved/', $display, 'der gespeicherte Satz ist kein Entwurf');
        self::assertStringNotContainsString(' IT ', $display, 'eine Klasse, die der Shop nicht nutzt');
        // Second CW-6: the row without a country (1, empty, 0) is missing.
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
     * 🎯 **The assertion that matters.** Produced by hand once on a real
     * installation — delete the shipping method, delete the tax class, restore
     * the shipping method from the recycle bin — and the section appeared.
     * That was proof for that state; here it is proof for every later one.
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
        // Counter-check: no subscription, no subscription rate.
        self::assertStringNotContainsString('cannot be billed again', $display);
    }

    /**
     * Abo-3: The subscription is read-only and shows no price —
     * "open record" does not help there, the damage is the next invoice.
     */
    public function testEinAboMitVerwaisterKlasseBekommtSeinenEigenenSatz(): void
    {
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 99]);

        $display = $this->diagnose()->getDisplay();

        self::assertStringContainsString('WEB-00090-1', $display);
        self::assertStringContainsString('cannot be billed again', $display);
    }

    /**
     * A product without any assignment belongs in the report just as much —
     * the damage is the same, and it cannot come about through the back end
     * at all (mandatory field), so it comes from an import or SQL.
     */
    public function testAuchEineLeereZuordnungStehtImBericht(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Ohne Klasse', 'taxClass' => 0]);

        self::assertStringContainsString('Ohne Klasse', $this->diagnose()->getDisplay());
    }

    /**
     * 🔴 **The path on which the section does NOT come.**
     *
     * `--class/--country` returns before the report. That is correct — the
     * question there is "which rate applies here", not "what is broken" —,
     * but it is the reason why a cursory look could take the section for
     * built where it never runs. Pinned down so that a later clean-up does
     * not remove the early return by accident.
     */
    public function testDerDirekteWegUeberEineKlasseBerichtetNichtUeberVerwaiste(): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'ZZ Probe Verwaist', 'taxClass' => 99]);

        $tester = $this->diagnose(['--class' => '1', '--country' => 'AT']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringNotContainsString('ZZ Probe Verwaist', $tester->getDisplay());
    }

    /**
     * The message names the shops that exist — otherwise the only option left
     * is trying out IDs, and nobody should have to guess.
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
