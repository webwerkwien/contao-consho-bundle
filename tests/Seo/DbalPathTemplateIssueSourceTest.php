<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Seo\DbalPathTemplateIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalPathTemplateIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_page');
    }

    public function testValidAndEmptyTemplatesAreNoFinding(): void
    {
        // Empty means the default template — no issue.
        $this->page(1, '{alias}');
        $this->page(2, '{manufacturer}/{alias}');
        $this->page(3, '');

        self::assertSame([], $this->issues());
    }

    public function testAnInvalidTemplateIsAFinding(): void
    {
        // Written past the backend: no placeholder that determines a product.
        $this->page(4, '{manufacturer}', 'Produkt');

        self::assertEquals(
            [new ConfigurationIssue(ConfigurationFinding::PathTemplateInvalid, ['count' => '1', 'pages' => 'Produkt (ID 4)'])],
            $this->issues(),
        );
    }

    public function testEveryKindOfInvalidTemplateCounts(): void
    {
        // The same rules as on save: unknown, doubled, without determination.
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
        // Another page with leftovers in the column is not an issue.
        $this->page(8, '{manufacturer}', 'Alt', 'regular');

        self::assertSame([], $this->issues());
    }

    public function testItIsAWarning(): void
    {
        // The fallback silently changes the addresses of all products of the page — nothing to click away.
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
