<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Setup;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Setup\PageCacheSetup;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class PageCacheSetupTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_page', 'tl_article', 'tl_content'] as $table) {
            DcaSchema::createCore($this->connection, $table);
        }

        $this->connection->insert('tl_page', ['id' => 1, 'title' => 'Shop DE', 'type' => 'root', 'published' => 1]);
    }

    public function testZustandAmStartpunkt(): void
    {
        self::assertSame(['enabled' => false, 'seconds' => 0], $this->cacheSetup()->state(1), 'Contao-Vorgabe');

        $this->connection->update('tl_page', ['includeCache' => 1, 'cache' => 0], ['id' => 1]);
        self::assertSame(['enabled' => false, 'seconds' => 0], $this->cacheSetup()->state(1), 'Haken ohne Dauer speichert nichts');

        $this->connection->update('tl_page', ['cache' => 86400], ['id' => 1]);
        self::assertSame(['enabled' => true, 'seconds' => 86400], $this->cacheSetup()->state(1));
    }

    public function testEinschaltenMitVersion(): void
    {
        // I: before the change, and after it — read at the call.
        $cached = fn (): int => (int) $this->connection->fetchOne('SELECT includeCache FROM tl_page WHERE id = 1');
        $versions = $this->createMock(Versions::class);
        $versions->expects(self::once())->method('initialize')->willReturnCallback(static fn () => self::assertSame(0, $cached()));
        $versions->expects(self::once())->method('create')->willReturnCallback(static fn () => self::assertSame(1, $cached()));

        $this->cacheSetup($versions)->enable(1, 1000);

        self::assertSame(['includeCache' => 1, 'cache' => 3600], array_map('intval', $this->connection->fetchAssociative('SELECT includeCache, cache FROM tl_page WHERE id = 1') ?: []));
    }

    public function testGemessenWerdenEinstiegsseiteUndDieErsteKatalogseiteDiesesStartpunkts(): void
    {
        // As on a real installation: same sorting, "/" delivers the page with alias index.
        $this->connection->insert('tl_page', ['id' => 4, 'pid' => 1, 'sorting' => 0, 'title' => 'Produkt', 'alias' => 'produkt', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 2, 'pid' => 1, 'sorting' => 0, 'title' => 'Home', 'alias' => 'index', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 5, 'pid' => 1, 'sorting' => 256, 'title' => 'Shop', 'published' => 1]);
        // One level deeper — and a foreign page with a smaller ID.
        $this->connection->insert('tl_page', ['id' => 3, 'pid' => 5, 'title' => 'Kräuter', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 8, 'title' => 'Andere Website', 'type' => 'root', 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 9, 'pid' => 8, 'title' => 'Fremder Katalog', 'published' => 1]);
        $this->connection->insert('tl_article', ['id' => 30, 'pid' => 3, 'published' => 1]);
        $this->connection->insert('tl_article', ['id' => 90, 'pid' => 9, 'published' => 1]);
        $this->connection->insert('tl_content', ['pid' => 90, 'ptable' => 'tl_article', 'type' => 'consho_product_list']);
        $this->connection->insert('tl_content', ['pid' => 30, 'ptable' => 'tl_article', 'type' => 'consho_product_list']);

        self::assertSame(
            [['title' => 'Home', 'url' => 'https://shop.example/page-2'], ['title' => 'Kräuter', 'url' => 'https://shop.example/page-3']],
            $this->cacheSetup()->targets(1),
            'Seite 9 hat zwar zuerst eine Produktliste, gehört aber zu einem anderen Startpunkt',
        );
        self::assertSame([['title' => 'Fremder Katalog', 'url' => 'https://shop.example/page-9']], $this->cacheSetup()->targets(8), 'und umgekehrt');
    }

    private function cacheSetup(Versions|null $versions = null): PageCacheSetup
    {
        $model = function (int $id): PageModel {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$id]);
            self::assertIsArray($row);

            $page = $this->createMock(PageModel::class);
            $page->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
                'rootId' => 9 === $id ? 8 : 1,
                default => $row[$key] ?? null,
            });

            return $page;
        };

        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(static fn (string $method, array $args): PageModel => $model((int) $args[0]));

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pages);
        $framework->method('createInstance')->willReturn($versions ?? $this->createStub(Versions::class));

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(static fn (PageModel $page): string => 'https://shop.example/page-'.$page->id);

        return new PageCacheSetup($this->connection, $framework, $this->createStub(UrlGeneratorInterface::class), $urls);
    }
}
