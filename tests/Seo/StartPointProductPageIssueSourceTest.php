<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\Routing\Page\PageRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;
use Webwerkwien\ContaoConshoBundle\Seo\StartPointProductPageIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * GARAN (`/shop2/` → 500): a start point whose first page —
 * by Contao's rule in findFirstPublishedByPid() — is a product page.
 */
class StartPointProductPageIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Shop 2', 'consho_shop' => 2, 'published' => 1]);
    }

    public function testDieErsteSeiteIstEineProduktseite(): void
    {
        $this->page(10, 128, ProductPageController::TYPE, 'Produkt');
        $this->page(11, 256, 'regular', 'Startseite');

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::StartPointForwardsToProductPage, $issues[0]->finding);
        self::assertSame(['root' => 'Shop 2', 'page' => 'Produkt'], $issues[0]->context);
        self::assertSame(2, $issues[0]->shopId);
        self::assertTrue($issues[0]->finding->isWarning());
    }

    public function testNachContaosRegelUebersprungenesZaehltNicht(): void
    {
        // Contao skips unpublished, expired and a type without an address
        // — the first page is then the start page.
        $this->page(10, 64, ProductPageController::TYPE, 'Produkt alt', published: false);
        $this->page(11, 96, 'folder', 'Ordner');
        $this->page(12, 112, ProductPageController::TYPE, 'Produkt abgelaufen', stop: '100');
        $this->page(13, 128, 'regular', 'Startseite');
        $this->page(14, 256, ProductPageController::TYPE, 'Produkt');

        self::assertSame([], $this->source()->issues());
    }

    /**
     * A page with the alias "index" answers the start point's address
     * itself — then Contao does not redirect (as in the "Contao Official
     * Demo", which would otherwise raise a false alarm). Also deeper in the tree.
     */
    public function testMitIndexSeiteLeitetNichtsWeiter(): void
    {
        $this->page(10, 128, ProductPageController::TYPE, 'Produkt');
        $this->page(11, 256, 'regular', 'Bereich');
        $this->connection->insert('tl_page', ['id' => 12, 'pid' => 11, 'type' => 'regular', 'title' => 'Home', 'alias' => 'index', 'published' => 1]);

        $withIndex = $this->source()->issues();
        $this->connection->update('tl_page', ['published' => ''], ['id' => 12]);
        $unpublished = $this->source()->issues();

        self::assertSame([], $withIndex);
        self::assertCount(1, $unpublished, 'unveröffentlicht zählt sie nicht');
    }

    /**
     * Contao takes the index route only for a
     * public page (start/stop, PublishedFilter) with an address
     * (RouteProvider). Expired, not yet released or a type without an
     * address — then the start point redirects again.
     */
    public function testIndexSeiteOhneRouteVerhindertNichts(): void
    {
        $this->page(10, 128, ProductPageController::TYPE, 'Produkt');
        $this->connection->insert('tl_page', ['id' => 12, 'pid' => 1, 'sorting' => 256, 'type' => 'regular', 'title' => 'Home', 'alias' => 'index', 'stop' => '100', 'published' => 1]);
        $expired = $this->source()->issues();
        $this->connection->update('tl_page', ['stop' => '', 'start' => (string) (time() + 3600)], ['id' => 12]);
        $notYet = $this->source()->issues();
        $this->connection->update('tl_page', ['start' => '', 'type' => 'folder'], ['id' => 12]);
        $noAddress = $this->source()->issues();
        $this->connection->update('tl_page', ['type' => 'regular'], ['id' => 12]);
        $live = $this->source()->issues();

        self::assertCount(1, $expired, 'abgelaufen');
        self::assertCount(1, $notYet, 'noch nicht freigegeben');
        self::assertCount(1, $noAddress, 'Typ ohne Adresse');
        self::assertSame([], $live);
    }

    /**
     * What counts is `sorting`, not the id; and Contao skips a
     * page whose start is still pending.
     */
    public function testErsteSeiteNachSortingUndStart(): void
    {
        $this->page(10, 256, 'regular', 'Startseite');
        $this->page(20, 128, ProductPageController::TYPE, 'Produkt');
        $bySorting = $this->source()->issues();
        $this->connection->update('tl_page', ['start' => (string) (time() + 3600)], ['id' => 20]);
        $notYet = $this->source()->issues();

        self::assertCount(1, $bySorting, 'kleineres sorting, größere Id');
        self::assertSame('Produkt', $bySorting[0]->context['page'] ?? null);
        self::assertSame([], $notYet, 'Start in der Zukunft');
    }

    private function source(): StartPointProductPageIssueSource
    {
        $pages = $this->createStub(PageRegistry::class);
        $pages->method('getUnroutableTypes')->willReturn(['folder']);

        return new StartPointProductPageIssueSource($this->connection, $pages);
    }

    private function page(int $id, int $sorting, string $type, string $title, bool $published = true, string $stop = ''): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'pid' => 1, 'sorting' => $sorting, 'type' => $type, 'title' => $title, 'published' => $published ? '1' : '', 'stop' => $stop]);
    }
}
