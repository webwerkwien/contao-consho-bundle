<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class ProductUrlGeneratorTest extends TestCase
{
    public function testPassesOnlyTheValuesThePatternUses(): void
    {
        /*
         * 🔴 The first version of this test asserted the opposite — that all
         * four values go in, because ContentUrlResolverInterface says unused
         * parameters are ignored. That promise covers a resolver's
         * getParametersForContent(), not values handed straight to generate().
         * Measured live on 2026-09-11, where it produced
         *
         *     /produkt/…/schwedenbitter.html?sku=SB-250&type=
         *
         * as a canonical address.
         */
        $page = $this->pageWithTemplate('{manufacturer}/{alias}');

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls
            ->expects(self::once())
            ->method('generate')
            ->with(
                $page,
                ['alias' => 'schwedenbitter', 'manufacturer' => 'kraeuterhaus'],
                UrlGeneratorInterface::ABSOLUTE_URL,
            )
            ->willReturn('https://example.org/produkt/kraeuterhaus/schwedenbitter.html')
        ;

        $generator = new ProductUrlGenerator(
            $urls,
            $this->repository(pageId: 5, values: [
                'alias' => 'schwedenbitter',
                'sku' => 'SB-250',
                'manufacturer' => 'kraeuterhaus',
                'type' => 'bitter',
            ]),
            $this->frameworkReturning([5 => $page, 2 => $this->rootPage()]),
            $this->shopResolver(),
            $this->shopLanguages(),
        );

        self::assertSame('https://example.org/produkt/kraeuterhaus/schwedenbitter.html', $generator->generate(1, 2));
    }

    public function testAVariantIsPreselectedByItsIdOnTheProductsAddress(): void
    {
        // Eine Variante hat keine eigene Adresse — sie wird auf der Seite des
        // Hauptprodukts über die ID vorgewählt. Die Werte der Vorlage bleiben
        // dabei beschränkt: kein sku, kein type.
        $page = $this->pageWithTemplate('{alias}');

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls
            ->expects(self::once())
            ->method('generate')
            ->with($page, ['alias' => 'schwedenbitter', 'variant' => '6'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/produkt/schwedenbitter.html?variant=6')
        ;

        $generator = new ProductUrlGenerator(
            $urls,
            $this->repository(pageId: 5, values: ['alias' => 'schwedenbitter', 'sku' => 'SB-250', 'type' => 'bitter']),
            $this->frameworkReturning([5 => $page, 2 => $this->rootPage()]),
            $this->shopResolver(),
            $this->shopLanguages(),
        );

        self::assertSame('/produkt/schwedenbitter.html?variant=6', $generator->generate(1, 2, UrlGeneratorInterface::ABSOLUTE_PATH, 6));
    }

    public function testNoVariantMeansNoParameter(): void
    {
        $page = $this->pageWithTemplate('{alias}');

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls
            ->expects(self::once())
            ->method('generate')
            ->with($page, ['alias' => 'schwedenbitter'], UrlGeneratorInterface::ABSOLUTE_PATH)
            ->willReturn('/produkt/schwedenbitter.html')
        ;

        $generator = new ProductUrlGenerator(
            $urls,
            $this->repository(pageId: 5, values: ['alias' => 'schwedenbitter']),
            $this->frameworkReturning([5 => $page, 2 => $this->rootPage()]),
            $this->shopResolver(),
            $this->shopLanguages(),
        );

        self::assertSame('/produkt/schwedenbitter.html', $generator->generate(1, 2, UrlGeneratorInterface::ABSOLUTE_PATH, 0));
    }

    public function testFallsBackToTheAliasPatternWhenTheStoredOneIsUnusable(): void
    {
        // Same fallback as the route builder, for the same reason: a pattern
        // written past the DCA must not take every address down with it.
        $page = $this->pageWithTemplate('{category}/{alias}');

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls
            ->expects(self::once())
            ->method('generate')
            ->with($page, ['alias' => 'schwedenbitter'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.org/produkt/schwedenbitter.html')
        ;

        $generator = new ProductUrlGenerator(
            $urls,
            $this->repository(pageId: 5, values: ['alias' => 'schwedenbitter', 'sku' => 'SB-250']),
            $this->frameworkReturning([5 => $page, 2 => $this->rootPage()]),
            $this->shopResolver(),
            $this->shopLanguages(),
        );

        self::assertSame('https://example.org/produkt/schwedenbitter.html', $generator->generate(1, 2));
    }

    public function testRefusesWhenTheSiteHasNoProductPage(): void
    {
        /*
         * Not an empty string: a link to "" renders as a link to the current
         * page, so a shop missing its product page would look like a shop whose
         * products all point at themselves. Failing while somebody is setting
         * the shop up is the cheaper outcome.
         */
        $generator = new ProductUrlGenerator(
            $this->createMock(ContentUrlGenerator::class),
            $this->repository(pageId: null, values: []),
            $this->frameworkReturning([]),
            $this->shopResolver(),
            $this->shopLanguages(),
        );

        $this->expectException(ProductPageMissing::class);
        $this->expectExceptionMessage('root page 2');

        $generator->generate(1, 2);
    }

    public function testRefusesWhenTheProductPageRowIsGone(): void
    {
        // The repository found an id, the model did not load — a deleted page
        // between two requests. Same outcome, because the alternative is a link
        // that silently goes nowhere.
        $generator = new ProductUrlGenerator(
            $this->createMock(ContentUrlGenerator::class),
            $this->repository(pageId: 5, values: []),
            $this->frameworkReturning([]),
            $this->shopResolver(),
            $this->shopLanguages(),
        );

        $this->expectException(ProductPageMissing::class);

        $generator->generate(1, 2);
    }

    public function testPassesTheRootPagesLanguageAndTheShopsFallbackToTheRepository(): void
    {
        /*
         * The point of Aufgabe 3: the generator must ask the repository for
         * the SAME language/fallback pair the address lookup would use, or
         * the two diverge and a generated address 404s in translation.
         *
         * Not $page->language: $page is the product page found under the
         * root, and that property only carries the startpoint's language
         * after loadDetails() has run — which generate() never calls on it.
         * The root page is loaded on its own via $rootPageId and its raw
         * `language` column is read directly, which is authoritative for a
         * root page without loadDetails().
         */
        $page = $this->pageWithTemplate('{manufacturer}/{alias}');
        $root = $this->rootPage(language: 'en', shopId: 3);

        $repository = $this->createMock(ProductPageRepositoryInterface::class);
        $repository->method('findForRootPage')->willReturn(5);
        $repository
            ->expects(self::once())
            ->method('urlValues')
            ->with(1, 'en', 'de')
            ->willReturn(['alias' => 'herbal-bitters', 'manufacturer' => 'herb-house'])
        ;

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.org/produkt/herb-house/herbal-bitters.html');

        $generator = new ProductUrlGenerator(
            $urls,
            $repository,
            $this->frameworkReturning([5 => $page, 2 => $root]),
            $this->shopResolver(),
            $this->shopLanguages(shopId: 3, fallback: 'de'),
        );

        // The repository mock's `expects(self::once())->with(1, 'en', 'de')`
        // above is the actual assertion; this just closes out the test.
        self::assertSame('https://example.org/produkt/herb-house/herbal-bitters.html', $generator->generate(1, 2));
    }

    private function pageWithTemplate(string $template): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('row')->willReturn(['conshoPathTemplate' => $template]);

        return $page;
    }

    /**
     * A root page, standing in for the target page's startpoint. Its raw
     * `language` column is what languageOf() reads — deliberately via __get,
     * the same magic accessor PageModel itself uses (Model::__get()), and not
     * via loadDetails(), which languageOf() never calls. `row()` carries
     * `consho_shop`, the field ShopResolver::shopIdOfPage() reads.
     */
    private function rootPage(string $language = '', int $shopId = 0): PageModel
    {
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->with('language')->willReturn($language);
        $root->method('row')->willReturn(['consho_shop' => $shopId]);

        return $root;
    }

    private function repository(int|null $pageId, array $values): ProductPageRepositoryInterface
    {
        $repository = $this->createMock(ProductPageRepositoryInterface::class);
        $repository->method('findForRootPage')->willReturn($pageId);
        $repository->method('urlValues')->willReturn($values);

        return $repository;
    }

    // ShopResolver and ShopLanguages are both `final` and cannot be doubled
    // with createMock() — real instances instead, same as ShopResolverTest
    // and ShopLanguagesTest do.

    private function shopResolver(): ShopResolver
    {
        return new ShopResolver($this->createMock(ContaoFramework::class));
    }

    private function shopLanguages(int $shopId = 0, string $fallback = ''): ShopLanguages
    {
        $connection = $this->sqliteConnection();

        if ($shopId > 0) {
            $connection->insert('tl_consho_shop', ['id' => $shopId, 'fallbackLanguage' => $fallback]);
        }

        return new ShopLanguages($connection);
    }

    private function sqliteConnection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        $connection->executeStatement(
            "CREATE TABLE tl_page (
                id INTEGER PRIMARY KEY,
                type TEXT NOT NULL DEFAULT '',
                language TEXT NOT NULL DEFAULT '',
                consho_shop INTEGER NOT NULL DEFAULT 0,
                consho_currency TEXT NOT NULL DEFAULT ''
            )",
        );
        DcaSchema::create($connection, 'tl_consho_shop');

        return $connection;
    }

    /**
     * @param array<int, PageModel> $pagesById findById($id) returns $pagesById[$id], or null
     */
    private function frameworkReturning(array $pagesById): ContaoFramework
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter
            ->method('__call')
            ->willReturnCallback(static fn (string $name, array $args): ?PageModel => 'findById' === $name ? ($pagesById[$args[0]] ?? null) : null)
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(PageModel::class)->willReturn($adapter);

        return $framework;
    }
}
