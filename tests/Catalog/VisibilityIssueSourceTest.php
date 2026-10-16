<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoConshoBundle\Catalog\ProjectionState;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityIssueSource;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityProjector;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityRows;
use Webwerkwien\ContaoConshoBundle\Command\VisibilityRebuildCommand;
use Webwerkwien\ContaoConshoBundle\Command\VisibilityVerifyCommand;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

/**
 * Befund auf der Startseite und die beiden Befehle der Projektion der
 * Sichtbarkeit (B-10). Shop 1 in EUR, Startpunkt de, ein bepreistes Produkt.
 */
class VisibilityIssueSourceTest extends TestCase
{
    use CatalogSchema;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->createCatalogSchema($this->connection);
        $this->createRootPageTable($this->connection);
        $this->insertShop(['id' => 1, 'title' => 'Kräuterhaus', 'currency' => 'EUR', 'fallbackLanguage' => 'de', 'country' => 'AT']);
        $this->insertRootPage(1, 'de', 1);
        $this->insertProduct(['id' => 1, 'pid' => 0, 'published' => 1]);
        $this->insertProductShop(['pid' => 1, 'shop' => 1]);
        $this->insertListPrice(['pid' => 1, 'currency' => 'EUR', 'country' => 'AT', 'net' => '10.0000', 'gross' => '12.0000']);
    }

    public function testAMatchingProjectionIsNoFinding(): void
    {
        $this->projector()->rebuild();

        self::assertSame([], (new VisibilityIssueSource($this->projector(), new ArrayAdapter()))->issues());
    }

    public function testADeviationIsAWarningWithItsCount(): void
    {
        // Nie gebaut: die Zeile von Produkt 1 fehlt.
        $issues = (new VisibilityIssueSource($this->projector(), new ArrayAdapter()))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::VisibilityDeviation, $issues[0]->finding);
        self::assertSame(['count' => '1'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning(), 'Nichts zum Quittieren: consho:visibility:rebuild behebt es.');
    }

    public function testTheStartPageDoesNotRecalculateOnEveryCallAndTheRebuildClearsIt(): void
    {
        $cache = new ArrayAdapter();
        $source = new VisibilityIssueSource($this->projector(), $cache);

        self::assertCount(1, $source->issues());

        // Behoben am Befehl vorbei: Die Startseite zeigt die gemerkte Zahl weiter …
        $this->projector()->rebuild();
        self::assertCount(1, $source->issues());

        // … bis der Befehl den Eintrag löscht.
        (new CommandTester(new VisibilityRebuildCommand($this->projector(), $this->connection, $cache)))->execute([]);
        self::assertSame([], $source->issues());
    }

    public function testRebuildWritesTheProjection(): void
    {
        $tester = new CommandTester(new VisibilityRebuildCommand($this->projector(), $this->connection));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('1 visibility row(s)', $tester->getDisplay());
        self::assertSame([], $this->projector()->verify());
    }

    public function testVerifyFailsOnADeviationAndNamesTheArticle(): void
    {
        $tester = new CommandTester(new VisibilityVerifyCommand($this->projector()));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('main of 1, from 12.0000', $tester->getDisplay());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product_visibility'), 'verify schreibt nie');
    }

    public function testVerifySucceedsOnAMatchingProjection(): void
    {
        $this->projector()->rebuild();

        self::assertSame(Command::SUCCESS, (new CommandTester(new VisibilityVerifyCommand($this->projector())))->execute([]));
    }

    private function projector(): VisibilityProjector
    {
        return new VisibilityProjector(
            $this->connection,
            new VisibilityRows(new VisibilityCriteria()),
            new ShopLanguages($this->connection),
            new ProjectionState($this->connection),
        );
    }
}
