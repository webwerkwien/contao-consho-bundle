<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\InsertTag\InsertTagParser;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlDecoder;
use Contao\PageModel;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Seo\ProductHead;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Mit echtem ResponseContext, echter HtmlHeadBag und echtem ProductUrlGenerator.
 *
 * Seite 123 „Produkt" unter Startpunkt 1, Produkt 1 „Schwedenbitter". Der Kopf
 * ist so vorbelegt, wie CoreResponseContextFactory ihn vor dem Element füllt:
 * Titel und Beschreibung der Seite, Robots `index,follow`.
 *
 * Contaos ContentUrlGenerator ist gedoppelt, verhält sich aber wie Symfonys
 * Generator: Werte, die die Route nicht kennt, hängt er als Query an. Käme
 * `variant` in den Canonical, stünde es also sichtbar in der Adresse.
 *
 * Jeder Aufruf prüft zusätzlich, dass Robots unverändert bleibt — nie
 * `noindex`, auch nicht auf einer Variantenadresse (Entscheidung 3, Block A 2).
 */
class ProductHeadTest extends TestCase
{
    private const PAGE_TITLE = 'Produkt';

    private const PAGE_DESCRIPTION = 'Unsere Produkte';

    private const CANONICAL = 'https://example.org/produkt/schwedenbitter.html';

    public function testTheHeadNamesTheProductAndPointsAtItsAddress(): void
    {
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur');

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame('Nach alter Rezeptur', $head->getMetaDescription());
        self::assertSame(self::CANONICAL, $head->getCanonicalUri());
    }

    public function testAnInputEncodedTitleIsDecodedTheWayTheCoreDoes(): void
    {
        // Wie CoreResponseContextFactory: HtmlDecoder::inputEncodedToPlainText().
        // Das Template kodiert beim Ausgeben selbst — sonst stünde "&amp;amp;"
        // im Titel. Der Teaser ist ein Textfeld ohne allowHtml, also ebenso.
        //
        // Hält zugleich die Reihenfolge fest: erst kürzen, dann dekodieren.
        // StringUtil::substr() entfernt Tags — dekodiert vorher, fiele „<süß>"
        // als vermeintliches Tag weg.
        $head = $this->apply('Kr&#228;uter &amp; Bitter', 'Bitter &lt;süß&gt;');

        self::assertSame('Kräuter & Bitter', $head->getTitle());
        self::assertSame('Bitter <süß>', $head->getMetaDescription());
    }

    // ── Beschreibung: eigene, sonst Kurztext gekürzt, sonst die der Seite ──

    public function testTheProductsOwnMetaDescriptionComesBeforeTheTeaser(): void
    {
        // Entscheidung Michael, 2026-09-14.
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', metaDescription: 'Schwedenbitter nach Originalrezept, 250 ml');

        self::assertSame('Schwedenbitter nach Originalrezept, 250 ml', $head->getMetaDescription());
    }

    public function testTheOwnMetaDescriptionIsDecodedAndTrimmedButNeverShortened(): void
    {
        // Wer sie selbst schreibt, bestimmt auch die Länge. Gekürzt wird nur der
        // Kurztext, der nicht für die Suchergebnisse geschrieben wurde.
        $long = str_repeat('Kr&#228;uter &amp; Bitter nach alter Rezeptur. ', 8);

        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', metaDescription: "\n  ".$long."\n");

        self::assertSame(trim(str_repeat('Kräuter & Bitter nach alter Rezeptur. ', 8)), $head->getMetaDescription());
    }

    public function testAMetaDescriptionOfOnlyWhiteSpaceFallsBackToTheTeaser(): void
    {
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', metaDescription: "\n  ");

        self::assertSame('Nach alter Rezeptur', $head->getMetaDescription());
    }

    public function testWithoutAMetaDescriptionALongTeaserIsShortenedAtAWordBoundary(): void
    {
        // 350 Zeichen. Contao kürzt eine Meta-Beschreibung nicht selbst
        // (HtmlHeadBag::setMetaDescription() übernimmt den Wert), also ProductHead
        // — auf rund 160 Zeichen, wortgenau, mit Auslassungszeichen.
        $teaser = str_repeat('Kräuterbitter nach alter Rezeptur. ', 10);

        $description = $this->apply('Schwedenbitter', $teaser)->getMetaDescription();

        self::assertStringEndsWith(' …', $description);
        self::assertLessThanOrEqual(162, mb_strlen($description));
        self::assertGreaterThan(140, mb_strlen($description));
        // Ein Anfang des Kurztexts, der vor einem Leerzeichen endet — kein halbes Wort.
        self::assertStringStartsWith(mb_substr($description, 0, -2).' ', $teaser);
    }

    public function testAShortTeaserIsTakenWhole(): void
    {
        $teaser = 'Kräuterbitter nach alter Rezeptur, mit 22 Kräutern angesetzt und in der Steiermark abgefüllt.';

        self::assertSame($teaser, $this->apply('Schwedenbitter', $teaser)->getMetaDescription());
    }

    public function testWithoutMetaDescriptionAndTeaserThePagesDescriptionStays(): void
    {
        // Kein Auszug aus der Beschreibung (Entscheidung 7, 2026-09-14 bestätigt).
        $head = $this->apply('Schwedenbitter', '');

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame(self::PAGE_DESCRIPTION, $head->getMetaDescription());
    }

    public function testATeaserOfOnlyWhiteSpaceKeepsThePagesDescription(): void
    {
        // Ein Textarea behält Zeilenumbrüche. Eine leere Meta-Beschreibung wäre
        // schlechter als die der Seite.
        $head = $this->apply('Schwedenbitter', "\n  ");

        self::assertSame(self::PAGE_DESCRIPTION, $head->getMetaDescription());
    }

    public function testTheProductAddressOverridesACanonicalLinkMaintainedOnThePage(): void
    {
        // Genau deshalb wird der Canonical gesetzt und nicht Contaos Standard
        // überlassen: Den gepflegten canonicalLink der Produktseite hat die
        // Factory schon eingetragen, er gälte sonst für jedes Produkt.
        $head = $this->pageHead();
        $head->setCanonicalUri('https://example.org/gepflegt.html');

        $this->apply('Schwedenbitter', '', $head);

        self::assertSame(self::CANONICAL, $head->getCanonicalUri());
    }

    public function testAVariantAddressIsCanonicalToItsProduct(): void
    {
        /*
         * Aufruf /produkt/schwedenbitter.html?variant=6. Titel und Canonical
         * gehören dem Hauptprodukt (Entscheidung 3 und 7, Googles Linie zu
         * Produktvarianten). Selbst ein an der Seite gepflegtes
         * canonicalKeepParams mit `variant` darf den Parameter nicht
         * zurückholen — ein gesetzter Canonical geht in HtmlHeadBag vor.
         */
        $head = $this->pageHead();
        $head->setKeepParamsForCanonical(['variant', 'utm_*']);

        $this->apply('Schwedenbitter', '', $head, 'https://example.org/produkt/schwedenbitter.html?variant=6');

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame(self::CANONICAL, $head->getCanonicalUri());
        self::assertSame(
            self::CANONICAL,
            $head->getCanonicalUriForRequest(Request::create('https://example.org/produkt/schwedenbitter.html?variant=6&utm_source=newsletter')),
        );
    }

    public function testWithoutAProductPageTheTitleIsSetButNoCanonical(): void
    {
        // ProductUrlGenerator wirft ProductPageMissing. Auf der Produktseite
        // selbst kaum denkbar — aber eine Ausnahme aus dem Kopf darf die Seite
        // nicht mitreißen, und eine leere Adresse ist kein Canonical.
        $head = $this->apply('Schwedenbitter', 'Nach alter Rezeptur', productPageId: null);

        self::assertSame('Schwedenbitter', $head->getTitle());
        self::assertSame('Nach alter Rezeptur', $head->getMetaDescription());
        self::assertSame('', $head->getCanonicalUri());
    }

    public function testWithoutAResponseContextNothingHappens(): void
    {
        // Etwa beim Rendern außerhalb einer Seite. Die Datenbank wird dann gar
        // nicht erst nach der Produktseite gefragt.
        $repository = $this->createMock(ProductPageRepositoryInterface::class);
        $repository->expects(self::never())->method('findForRootPage');

        $this->productHead(new ResponseContextAccessor(new RequestStack()), $repository)
            ->apply(1, 1, 'Schwedenbitter', '', 'Nach alter Rezeptur')
        ;
    }

    public function testAResponseContextWithoutAHeadIsLeftAlone(): void
    {
        // createResponseContext() ohne Webseite legt keine HtmlHeadBag an.
        $context = new ResponseContext();

        $this->productHead($this->accessor($context), $this->repository(123))
            ->apply(1, 1, 'Schwedenbitter', '', 'Nach alter Rezeptur')
        ;

        self::assertFalse($context->has(HtmlHeadBag::class));
    }

    private function apply(
        string $title,
        string $teaser,
        ?HtmlHeadBag $head = null,
        string $uri = self::CANONICAL,
        ?int $productPageId = 123,
        string $metaDescription = '',
    ): HtmlHeadBag {
        $head ??= $this->pageHead();
        $robots = $head->getMetaRobots();

        $context = new ResponseContext();
        $context->add($head);

        $this->productHead($this->accessor($context, $uri), $this->repository($productPageId))->apply(1, 1, $title, $metaDescription, $teaser);

        self::assertSame($robots, $head->getMetaRobots(), 'ProductHead setzt nie Robots — kein noindex (Entscheidung 3).');

        return $head;
    }

    private function pageHead(): HtmlHeadBag
    {
        return (new HtmlHeadBag())->setTitle(self::PAGE_TITLE)->setMetaDescription(self::PAGE_DESCRIPTION);
    }

    private function productHead(ResponseContextAccessor $accessor, ProductPageRepositoryInterface $repository): ProductHead
    {
        $insertTags = $this->createMock(InsertTagParser::class);
        $insertTags->method('replaceInline')->willReturnArgument(0);

        return new ProductHead($accessor, $this->productUrls($repository), new HtmlDecoder($insertTags));
    }

    private function accessor(ResponseContext $context, string $uri = self::CANONICAL): ResponseContextAccessor
    {
        $request = Request::create($uri);
        $request->attributes->set(ResponseContext::REQUEST_ATTRIBUTE_NAME, $context);

        $stack = new RequestStack();
        $stack->push($request);

        return new ResponseContextAccessor($stack);
    }

    private function repository(?int $productPageId): ProductPageRepositoryInterface
    {
        $repository = $this->createMock(ProductPageRepositoryInterface::class);
        $repository->method('findForRootPage')->willReturn($productPageId);
        $repository->method('urlValues')->willReturn(['alias' => 'schwedenbitter', 'sku' => 'SB-250']);

        return $repository;
    }

    /**
     * Der echte ProductUrlGenerator — er ist final. Gedoppelt sind nur seine
     * Zulieferer, wie in ProductUrlGeneratorTest.
     */
    private function productUrls(ProductPageRepositoryInterface $repository): ProductUrlGenerator
    {
        // Wie Symfonys Generator: {alias} kennt die Route, alles andere hängt
        // er als Query an.
        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(
            static function (object $content, array $values): string {
                $alias = $values['alias'] ?? '';
                unset($values['alias']);

                return 'https://example.org/produkt/'.$alias.'.html'.([] === $values ? '' : '?'.http_build_query($values));
            },
        );

        $productPage = $this->createMock(PageModel::class);
        $productPage->method('row')->willReturn(['conshoPathTemplate' => '{alias}']);

        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturn('');
        $root->method('row')->willReturn(['consho_shop' => 0]);

        $pages = [123 => $productPage, 1 => $root];

        $adapter = $this->createMock(Adapter::class);
        $adapter
            ->method('__call')
            ->willReturnCallback(static fn (string $name, array $args): ?PageModel => 'findById' === $name ? ($pages[$args[0]] ?? null) : null)
        ;

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(PageModel::class)->willReturn($adapter);

        // Dasselbe Schema wie in ProductUrlGeneratorTest — ShopLanguages fragt beide.
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

        return new ProductUrlGenerator(
            $urls,
            $repository,
            $framework,
            new ShopResolver($this->createMock(ContaoFramework::class)),
            new ShopLanguages($connection),
        );
    }
}
