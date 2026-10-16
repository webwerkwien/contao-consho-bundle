<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Seo\DbalPathTemplateIssueSource;

class DbalPathTemplateIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement(
            "CREATE TABLE tl_page (id INTEGER PRIMARY KEY, type VARCHAR(64) NOT NULL DEFAULT '', title VARCHAR(255) NOT NULL DEFAULT '', conshoPathTemplate VARCHAR(255) NOT NULL DEFAULT '')",
        );
    }

    public function testValidAndEmptyTemplatesAreNoFinding(): void
    {
        // Leer heißt Standardvorlage — kein Befund.
        $this->page(1, '{alias}');
        $this->page(2, '{manufacturer}/{alias}');
        $this->page(3, '');

        self::assertSame([], $this->issues());
    }

    public function testAnInvalidTemplateIsAFinding(): void
    {
        // Am Backend vorbei geschrieben: kein Platzhalter, der ein Produkt bestimmt.
        $this->page(4, '{manufacturer}', 'Produkt');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::PathTemplateInvalid, ['count' => '1', 'pages' => 'Produkt (ID 4)'])],
            $this->issues(),
        );
    }

    public function testEveryKindOfInvalidTemplateCounts(): void
    {
        // Dieselben Regeln wie beim Speichern: unbekannt, doppelt, ohne Bestimmung.
        $this->page(5, '{unbekannt}/{alias}', 'A');
        $this->page(6, '{alias}/{alias}', 'B');
        $this->page(7, 'fest', 'C');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::PathTemplateInvalid, ['count' => '3', 'pages' => 'A (ID 5), B (ID 6), C (ID 7)'])],
            $this->issues(),
        );
    }

    public function testOnlyProductPagesAreRead(): void
    {
        // Eine andere Seite mit Resten in der Spalte ist kein Befund.
        $this->page(8, '{manufacturer}', 'Alt', 'regular');

        self::assertSame([], $this->issues());
    }

    public function testItIsAWarning(): void
    {
        // Der Rückfall ändert still die Adressen aller Produkte der Seite — nichts zum Wegklicken.
        self::assertTrue(ConfigurationFinding::PathTemplateInvalid->isWarning());
    }

    private function page(int $id, string $template, string $title = 'Produkt', string $type = 'consho_product'): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'type' => $type, 'title' => $title, 'conshoPathTemplate' => $template]);
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        return (new DbalPathTemplateIssueSource($this->connection))->issues();
    }
}
