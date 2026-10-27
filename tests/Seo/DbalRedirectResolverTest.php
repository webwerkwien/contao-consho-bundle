<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Seo\DbalRedirectResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalRedirectResolverTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_url_redirect');
    }

    public function testFindsTheProductBehindASupersededAddress(): void
    {
        $this->root(1, shop: 1);
        $this->redirect(1, '/produkt/schwedenbitter.html', 10);

        $target = (new DbalRedirectResolver($this->connection))->find('/produkt/schwedenbitter.html', 'example.org');

        self::assertNotNull($target);
        self::assertSame(10, $target->productId);
        self::assertSame(1, $target->rootPageId);
        self::assertSame(1, $target->shopId);
    }

    public function testNamesTheLanguageOfTheStartpoint(): void
    {
        // ProductRedirectListener asks visibility in it: a product without a
        // French translation must not be redirected to on a French startpoint.
        $this->root(2, dns: 'fr.example.org', shop: 1, language: 'fr');
        $this->redirect(2, '/produit/amer-suedois.html', 10);

        self::assertSame('fr', (new DbalRedirectResolver($this->connection))->find('/produit/amer-suedois.html', 'fr.example.org')?->language);
    }

    public function testReturnsNothingForAnAddressNobodyRecorded(): void
    {
        $this->root(1, shop: 1);

        self::assertNull((new DbalRedirectResolver($this->connection))->find('/produkt/nie-dagewesen.html', 'example.org'));
    }

    public function testTellsTwoSitesOfOneInstallationApart(): void
    {
        /*
         * The same path under two root pages. Without the host the lookup would
         * return whichever row came first, and half the redirects would send
         * visitors into the wrong language.
         */
        $this->root(1, dns: 'de.example.org', shop: 1);
        $this->root(2, dns: 'fr.example.org', shop: 2);
        $this->redirect(1, '/produkt/schwedenbitter.html', 10);
        $this->redirect(2, '/produkt/schwedenbitter.html', 20);

        $resolver = new DbalRedirectResolver($this->connection);

        self::assertSame(10, $resolver->find('/produkt/schwedenbitter.html', 'de.example.org')?->productId);
        self::assertSame(20, $resolver->find('/produkt/schwedenbitter.html', 'fr.example.org')?->productId);
    }

    public function testASiteWithoutADomainAnswersForEveryHost(): void
    {
        // Contao's own rule for a root page with an empty dns.
        $this->root(1, shop: 1);
        $this->redirect(1, '/produkt/schwedenbitter.html', 10);

        self::assertSame(10, (new DbalRedirectResolver($this->connection))->find('/produkt/schwedenbitter.html', 'irgendwas.example')?->productId);
    }

    public function testTheHostSpecificSiteWinsOverTheCatchAll(): void
    {
        // Otherwise a domain-less site would quietly take over addresses that
        // belong to a configured domain.
        $this->root(1, shop: 1);
        $this->root(2, dns: 'de.example.org', shop: 2);
        $this->redirect(1, '/produkt/schwedenbitter.html', 10);
        $this->redirect(2, '/produkt/schwedenbitter.html', 20);

        self::assertSame(20, (new DbalRedirectResolver($this->connection))->find('/produkt/schwedenbitter.html', 'de.example.org')?->productId);
    }

    public function testReturnsNothingWhenTheSiteStoppedBeingAShop(): void
    {
        $this->root(1, shop: 0);
        $this->redirect(1, '/produkt/schwedenbitter.html', 10);

        self::assertNull((new DbalRedirectResolver($this->connection))->find('/produkt/schwedenbitter.html', 'example.org'));
    }

    private function root(int $id, string $dns = '', int $shop = 0, string $language = ''): void
    {
        $this->connection->insert('tl_page', ['id' => $id, 'dns' => $dns, 'language' => $language, 'consho_shop' => $shop]);
    }

    private function redirect(int $rootId, string $path, int $productId): void
    {
        $this->connection->insert('tl_consho_url_redirect', [
            'pid' => $rootId,
            'oldPath' => $path,
            'productId' => $productId,
            'createdAt' => 1,
        ]);
    }
}
