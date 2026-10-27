<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ListElementOptionsListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The lists that a filter element can refer to explicitly (conshoListElement).
 */
class ListElementOptionsListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Types and defaults as in core-bundle 5.7 and Consho's tl_content DCA.
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->executeStatement(
            "CREATE TABLE tl_article (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, title VARCHAR(255) NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_theme (id INTEGER PRIMARY KEY, name VARCHAR(128) NOT NULL DEFAULT '')",
        );
        $this->connection->executeStatement(
            "CREATE TABLE tl_content (
                id INTEGER PRIMARY KEY,
                pid INTEGER NOT NULL DEFAULT 0,
                ptable VARCHAR(64) NOT NULL DEFAULT 'tl_article',
                type VARCHAR(64) NOT NULL DEFAULT 'text',
                conshoFollowsAddress INTEGER NOT NULL DEFAULT 1
            )",
        );

        $this->connection->insert('tl_page', ['id' => 12, 'title' => 'Kräuterbitter']);
        $this->connection->insert('tl_page', ['id' => 13, 'title' => 'Tees']);
        $this->connection->insert('tl_article', ['id' => 120, 'pid' => 12, 'title' => 'Hauptinhalt']);
        $this->connection->insert('tl_article', ['id' => 130, 'pid' => 13, 'title' => 'Hauptinhalt']);
        $this->connection->insert('tl_theme', ['id' => 1, 'name' => 'Kräuterladen']);
    }

    public function testNamesAListingByPageArticleAndId(): void
    {
        $this->element(636, 120);
        $this->element(640, 130);

        self::assertSame(
            [636 => 'Kräuterbitter › Hauptinhalt › ID 636', 640 => 'Tees › Hauptinhalt › ID 640'],
            $this->options(),
        );
    }

    public function testOffersAListingInALayoutSectionByItsTheme(): void
    {
        // The field exists precisely for them: firstOnPage() only searches in articles.
        $this->element(700, 1, ptable: 'tl_theme');

        self::assertSame([700 => 'Theme Kräuterladen › ID 700'], $this->options());
    }

    public function testLeavesOutAListingThatDoesNotFollowTheAddress(): void
    {
        // The filter element renders nothing for them anyway.
        $this->element(636, 120);
        $this->element(637, 120, follows: false);

        self::assertSame([636], array_keys($this->options()));
    }

    public function testLeavesOutElementsOfAnotherType(): void
    {
        $this->element(636, 120);
        $this->element(638, 120, type: 'text');
        $this->element(639, 120, type: 'consho_product_filter');

        self::assertSame([636], array_keys($this->options()));
    }

    /**
     * @return array<int, string>
     */
    private function options(): array
    {
        return (new ListElementOptionsListener($this->connection))();
    }

    private function element(int $id, int $pid, string $ptable = 'tl_article', string $type = 'consho_product_list', bool $follows = true): void
    {
        $this->connection->insert('tl_content', [
            'id' => $id, 'pid' => $pid, 'ptable' => $ptable, 'type' => $type, 'conshoFollowsAddress' => (int) $follows,
        ]);
    }
}
