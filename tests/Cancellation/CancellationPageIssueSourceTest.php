<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Cancellation;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationPageIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-7, P63: Ein Shop, der Abos verkauft, braucht eine erreichbare Seite mit
 * „Verträge hier kündigen" — ohne Anmeldung (§ 312k BGB).
 */
final class CancellationPageIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, title TEXT NOT NULL DEFAULT '', type TEXT NOT NULL DEFAULT 'regular',
             published INTEGER NOT NULL DEFAULT 1, protected INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '',
             consho_shop INTEGER NOT NULL DEFAULT 0, consho_cancellationPage INTEGER NOT NULL DEFAULT 0)",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, published INTEGER NOT NULL DEFAULT 1,
             start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, ptable TEXT NOT NULL DEFAULT 'tl_article',
             type TEXT NOT NULL DEFAULT 'text', invisible INTEGER NOT NULL DEFAULT 0, start TEXT NOT NULL DEFAULT '', stop TEXT NOT NULL DEFAULT '')",
        );
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');
        DcaSchema::create($this->connection, 'tl_consho_subscription');

        // Shop 1 verkauft ein Abo-Produkt; Startpunkt 1 mit Kündigungsseite 10.
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop']);
        $this->connection->insert('tl_consho_product', ['id' => 4, 'published' => 1, 'saleSubscription' => 1]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 4, 'shop' => 1]);
        $this->connection->insert('tl_page', ['id' => 1, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1, 'consho_cancellationPage' => 10]);
        $this->connection->insert('tl_page', ['id' => 10, 'title' => 'Kündigen']);
        $this->connection->insert('tl_article', ['id' => 20, 'pid' => 10]);
        $this->connection->insert('tl_content', ['id' => 30, 'pid' => 20, 'type' => 'consho_cancellation']);
    }

    public function testEingerichtetMeldetNichts(): void
    {
        self::assertSame([], $this->issues());
    }

    public function testOhneSeite(): void
    {
        $this->connection->update('tl_page', ['consho_cancellationPage' => 0], ['id' => 1]);

        $issues = $this->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::CancellationPageMissing, $issues[0]->finding);
        self::assertSame(['shop' => 'Webshop', 'roots' => 'Shop DE'], $issues[0]->context);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, int}>
     */
    public static function unerreichbar(): iterable
    {
        yield 'Seite nicht veröffentlicht' => ['tl_page', ['published' => 0], 10];
        yield 'Seite geschützt' => ['tl_page', ['protected' => 1], 10];
        yield 'Seite abgelaufen' => ['tl_page', ['stop' => '1000'], 10];
        yield 'Artikel versteckt' => ['tl_article', ['published' => 0], 20];
        yield 'Element versteckt' => ['tl_content', ['invisible' => 1], 30];
        yield 'anderes Element' => ['tl_content', ['type' => 'consho_withdrawal'], 30];
    }

    /**
     * @param array<string, mixed> $values
     *
     * @dataProvider unerreichbar
     */
    public function testNichtErreichbar(string $table, array $values, int $id): void
    {
        $this->connection->update($table, $values, ['id' => $id]);

        self::assertCount(1, $this->issues());
    }

    /**
     * N1 (Nachlese Stufe 2): Ein unveröffentlichter Startpunkt meldet nichts,
     * für die Einrichtungsseite aber schon.
     */
    public function testUnveroeffentlichterStartpunktNurFuerDieEinrichtung(): void
    {
        $this->connection->update('tl_page', ['consho_cancellationPage' => 0, 'published' => 0], ['id' => 1]);

        self::assertSame([], $this->issues());

        $issues = (new CancellationPageIssueSource($this->connection))->issuesForSetup();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::CancellationPageMissing, $issues[0]->finding);
    }

    public function testEineEbeneTieferInEinemContainer(): void
    {
        $this->connection->update('tl_content', ['type' => 'element_group'], ['id' => 30]);
        $this->connection->insert('tl_content', ['id' => 31, 'pid' => 30, 'ptable' => 'tl_content', 'type' => 'consho_cancellation']);

        self::assertSame([], $this->issues());
    }

    public function testOhneAbosKeinBefund(): void
    {
        $this->connection->update('tl_page', ['consho_cancellationPage' => 0], ['id' => 1]);
        $this->connection->update('tl_consho_product', ['saleSubscription' => 0], ['id' => 4]);

        $withoutSubscriptions = $this->issues();
        self::assertSame([], $withoutSubscriptions);

        // Ein laufendes Abo reicht auch ohne Abo-Produkt im Verkauf.
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-1-1', 'shop' => 1, 'state' => 'active']);

        $withRunning = $this->issues();
        self::assertCount(1, $withRunning);

        // Pausiert ist auch ein laufender Vertrag (P106).
        $this->connection->update('tl_consho_subscription', ['state' => 'paused'], ['subscriptionNumber' => 'WEB-1-1']);
        self::assertCount(1, $this->issues());
    }

    /**
     * @return list<\Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new CancellationPageIssueSource($this->connection))->issues();
    }
}
