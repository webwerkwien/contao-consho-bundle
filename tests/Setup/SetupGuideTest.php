<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\DbalShopScopeIssueSource;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;
use Webwerkwien\ContaoConshoBundle\Setup\SetupStep;
use Webwerkwien\ContaoConshoBundle\Setup\StepState;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class SetupGuideTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Review I (6): Contaos eigene DCA, nicht abgeschrieben (Fall 31).
        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Shop DE']);
    }

    public function testOhneWaehrungAmStartpunktIstErNichtBereit(): void
    {
        // Review I, S1: diese Befunde sperren die Kasse nicht — sie zeigt
        // ohne Währung einfach nichts. „Bereit" darf das nicht übersehen.
        $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 1]);

        foreach (SetupGuide::UNSELLABLE as $finding) {
            $setup = (new SetupGuide($this->connection))->forShop(1, 'Shop DE', [new ConfigurationIssue($finding, [], 1)], self::NOW);

            self::assertFalse($setup->ready, $finding->value);
            self::assertSame(StepState::OPEN, $this->stepStatus($setup->steps, SetupStep::Shop), $finding->value);
        }

        // Gegenprobe: die Domain fehlt — das stört Mails aus dem Cron, nicht den Kauf.
        self::assertTrue((new SetupGuide($this->connection))->forShop(1, 'Shop DE', [new ConfigurationIssue(ConfigurationFinding::StartPointWithoutDomain, [], 1)], self::NOW)->ready);
    }

    public function testDieFehlendeWaehrungEinesShopsSperrtNichtDenAnderen(): void
    {
        // Zweites Review I, B1: die Befunde kamen mit Shop 0, und Shop 0 hieß
        // „alle". Diesmal aus der echten Quelle, nicht von Hand gebaut.
        $this->connection->update('tl_consho_shop', ['currencies' => serialize(['EUR'])], ['id' => 1]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Shop CH', 'currencies' => serialize(['CHF'])]);
        $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 1, 'consho_currency' => 'EUR']);
        $this->page(['id' => 2, 'type' => 'root', 'consho_shop' => 2, 'consho_currency' => '']);

        $issues = (new DbalShopScopeIssueSource($this->connection))->issues();
        $guide = new SetupGuide($this->connection);

        self::assertContains(ConfigurationFinding::RootWithoutCurrency, array_map(static fn (ConfigurationIssue $issue): ConfigurationFinding => $issue->finding, $issues));
        self::assertTrue($guide->forShop(1, 'Shop DE', $issues, self::NOW)->ready);
        self::assertFalse($guide->forShop(2, 'Shop CH', $issues, self::NOW)->ready);
    }

    /**
     * Veröffentlicht, wenn nichts anderes gesagt ist — Contaos Vorgabe ist 0.
     *
     * @param array<string, mixed> $row
     */
    private function page(array $row): void
    {
        $this->connection->insert('tl_page', ['published' => 1, ...$row]);
    }

    public function testOhneStartpunktIstDerShopNichtBereitAuchOhneBefund(): void
    {
        // I7: ohne Startpunkt meldet keine Seitenprüfung etwas — die
        // Kaufsperre allein hielte den Shop für bereit.
        $setup = (new SetupGuide($this->connection))->forShop(1, 'Shop DE', [], self::NOW);

        self::assertFalse($setup->ready);
        self::assertSame(StepState::OPEN, $this->stepStatus($setup->steps, SetupStep::Shop));
        self::assertSame(StepState::DONE, $this->stepStatus($setup->steps, SetupStep::Mail));
    }

    public function testMitStartpunktUndOhneSperreIstErBereit(): void
    {
        $this->page(['id' => 1, 'title' => 'Shop DE', 'type' => 'root', 'language' => 'de', 'consho_shop' => 1]);

        $setup = (new SetupGuide($this->connection))->forShop(1, 'Shop DE', [], self::NOW);

        self::assertTrue($setup->ready);
        self::assertSame([['id' => 1, 'title' => 'Shop DE', 'language' => 'de']], $setup->roots);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function unreachableRoots(): iterable
    {
        yield 'unveröffentlicht' => [['published' => 0]];
        yield 'noch nicht gestartet' => [['start' => (string) (self::NOW + 3600)]];
        yield 'abgelaufen' => [['stop' => '1000']];
        yield 'anderer Shop' => [['consho_shop' => 2]];
        yield 'keine Wurzel' => [['type' => 'regular']];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unreachableRoots')]
    public function testNurEinErreichbarerStartpunktZaehlt(array $override): void
    {
        $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 1, ...$override]);

        self::assertSame([], (new SetupGuide($this->connection))->liveRoots(1, self::NOW));
    }

    public function testEinSperrenderBefundOeffnetSeinenSchrittUndNimmtDieBereitschaft(): void
    {
        $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 1]);

        $setup = (new SetupGuide($this->connection))->forShop(1, 'Shop DE', [
            new ConfigurationIssue(ConfigurationFinding::MailTransportMissing, ['shop' => 'Shop DE'], 1),
        ], self::NOW);

        self::assertFalse($setup->ready);
        self::assertSame(StepState::OPEN, $this->stepStatus($setup->steps, SetupStep::Mail));
    }

    public function testEineNichtSperrendeWarnungIstNurAufmerksamkeit(): void
    {
        $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 1]);

        $setup = (new SetupGuide($this->connection))->forShop(1, 'Shop DE', [
            new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, [], 1),
        ], self::NOW);

        self::assertTrue($setup->ready);
        self::assertSame(StepState::ATTENTION, $this->stepStatus($setup->steps, SetupStep::Shipping));
    }

    public function testBefundeAndererShopsZaehlenNichtDieOhneShopSchon(): void
    {
        $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 1]);

        $setup = (new SetupGuide($this->connection))->forShop(1, 'Shop DE', [
            new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, [], 2),
            new ConfigurationIssue(ConfigurationFinding::CartPageMissing, [], 0),
        ], self::NOW);

        self::assertSame(StepState::DONE, $this->stepStatus($setup->steps, SetupStep::Payment));
        self::assertSame(StepState::OPEN, $this->stepStatus($setup->steps, SetupStep::ShopPages));
    }

    public function testBetriebsbefundeStehenUnterDenUebrigenHinweiseNicht(): void
    {
        $this->page(['id' => 1, 'type' => 'root', 'consho_shop' => 1]);

        $setup = (new SetupGuide($this->connection))->forShop(1, 'Shop DE', [
            new ConfigurationIssue(ConfigurationFinding::StockLow, [], 1),
            new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, [], 1),
        ], self::NOW);

        self::assertCount(1, $setup->others, 'die Warnung ja, der Hinweis nicht');
        self::assertSame(ConfigurationFinding::StockLow, $setup->others[0]->finding);
    }

    public function testShopsInReihenfolgeDerId(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'title' => 'Shop AT']);

        self::assertSame([1 => 'Shop DE', 3 => 'Shop AT'], (new SetupGuide($this->connection))->shops());
    }

    /**
     * @param list<StepState> $steps
     */
    private function stepStatus(array $steps, SetupStep $step): string
    {
        foreach ($steps as $state) {
            if ($state->step === $step) {
                return $state->status;
            }
        }

        self::fail('Schritt fehlt: '.$step->value);
    }
}
