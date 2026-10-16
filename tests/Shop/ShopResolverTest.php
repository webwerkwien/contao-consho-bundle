<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shop;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

class ShopResolverTest extends TestCase
{
    public function testResolvesAShopById(): void
    {
        $shop = $this->createMock(ShopModel::class);
        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [7], $shop);

        $resolver = new ShopResolver($framework);

        self::assertSame($shop, $resolver->resolveById(7));
    }

    public function testReturnsNullWithoutQueryingWhenNoShopIsAssigned(): void
    {
        // A Contao installation without a shop must not query for one on every
        // request. The guard sits before the framework, not after it.
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->resolveById(0));
        self::assertNull($resolver->resolveById(-1));
    }

    public function testReturnsNullWhenTheShopIdPointsNowhere(): void
    {
        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [99], null);

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->resolveById(99));
    }

    public function testResolvesARootPageThroughItsOwnField(): void
    {
        $shop = $this->createMock(ShopModel::class);
        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [7], $shop);

        $page = $this->page(id: 5, rootId: 5, shopId: 7);

        $resolver = new ShopResolver($framework);

        self::assertSame($shop, $resolver->resolveForPage($page));
    }

    public function testResolvesASubpageThroughItsRootPage(): void
    {
        $shop = $this->createMock(ShopModel::class);
        $root = $this->page(id: 5, rootId: 5, shopId: 7);

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->with('findByPk', [5])->willReturn($root);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->with('findByPk', [7])->willReturn($shop);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pageAdapter],
            [ShopModel::class, $shopAdapter],
        ]);

        $resolver = new ShopResolver($framework);

        self::assertSame($shop, $resolver->resolveForPage($this->subpage(id: 12, rootId: 5)));
    }

    public function testEineNichtAufbereiteteUnterseiteHatKeinenStartpunktStattSelbstEinerZuSein(): void
    {
        /*
         * 🔴 Der Fehler vom 2026-09-20 an seiner Wurzel. Hier stand
         * `(int) $page->rootId ?: $pageId` — „keine rootId? dann bist du selbst
         * der Startpunkt". Das gilt für eine Seite vom Typ `root` und für
         * sonst keine. Eine Unterseite aus findById() bekam damit sich selbst
         * zurück, trug kein `consho_shop`, und „in den Warenkorb" antwortete
         * von jeder Unterseite mit 400.
         *
         * Nicht zu verwechseln mit dem Fall „Startpunkt gelöscht": Dort ist
         * die rootId bekannt und der Datensatz weg (siehe der Test darunter).
         * Hier ist die rootId selbst unbekannt — und darauf ist die einzig
         * ehrliche Antwort „ich weiß es nicht", nicht „ich bin es".
         */
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->rootOf($this->unwalkedSubpage(id: 12, rootId: 5)));
    }

    public function testReturnsNullWhenTheRootPageIsGone(): void
    {
        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturn(null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertNull($resolver->resolveForPage($this->page(id: 12, rootId: 5, shopId: 0)));
    }

    public function testDieWaehrungKommtVomStartpunktUndNichtVomShop(): void
    {
        // Spec 4.0: Die Währung hängt am Startpunkt, das Heimatland am Shop.
        // Ein österreichischer Shop, der über /en/ in Pfund verkauft, ist das
        // Marktpaar (GBP, AT) — und genau diese Aufteilung prüft der Test:
        // Der Shop trägt hier ausdrücklich eine andere Währung.
        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([['id', 7], ['currency', 'EUR'], ['country', 'AT']]);

        $framework = $this->frameworkFinding(ShopModel::class, 'findByPk', [7], $shop);

        $page = $this->page(id: 5, rootId: 5, shopId: 7, currency: 'GBP');

        $resolver = new ShopResolver($framework);

        self::assertSame('GBP', $resolver->currencyOfPage($page));
    }

    public function testEineUnterseiteErbtDieWaehrungIhresStartpunkts(): void
    {
        // `consho_currency` steht nur am Startpunkt. Die Unterseite, von der
        // aus gekauft wird, trägt die Spalte leer — wer sie dort liest, liest
        // nichts.
        $root = $this->page(id: 5, rootId: 5, shopId: 7, currency: 'GBP');

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->with('findByPk', [5])->willReturn($root);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertSame('GBP', $resolver->currencyOfPage($this->subpage(id: 12, rootId: 5)));
    }

    public function testDerBaumWirdHochgelaufenBevorNachDemStartpunktGefragtWird(): void
    {
        /*
         * ⚠️ Der Fehler vom 2026-09-20 in seiner eigenen Prüfung: `rootId` ist
         * keine Spalte von tl_page, sondern eine Eigenschaft, die PageModel
         * beim Hochlaufen des Baums setzt. Eine Seite aus findById() hat sie
         * nicht — rootOf() gäbe dann die Unterseite selbst zurück, und die
         * trägt keine Währung.
         *
         * Das Doppel unten gibt `rootId` deshalb erst nach loadDetails()
         * heraus. Ohne den Aufruf in currencyOfPage() bleibt hier '' übrig.
         */
        $root = $this->page(id: 5, rootId: 5, shopId: 7, currency: 'GBP');

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->with('findByPk', [5])->willReturn($root);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertSame('GBP', $resolver->currencyOfPage($this->unwalkedSubpage(id: 12, rootId: 5)));
    }

    public function testOhneGewaehlteWaehrungKommtEineLeereZeichenketteZurueck(): void
    {
        // Kein Euro als Notnagel: Ein Startpunkt ohne Währung soll als solcher
        // sichtbar bleiben. Wer damit rechnet, findet keinen Preis — das ist
        // die gewollte Folge, nicht ein Preis in einer Währung, die niemand
        // ausgewählt hat.
        $framework = $this->createMock(ContaoFramework::class);

        $resolver = new ShopResolver($framework);

        self::assertSame('', $resolver->currencyOfPage($this->page(id: 5, rootId: 5, shopId: 7, currency: '')));
    }

    public function testEinStartpunktOhneDieSpalteIstKeinEuroStartpunkt(): void
    {
        /*
         * Die Spalte fehlt in der Zeile, nicht nur ihr Wert: der Stand
         * zwischen Deploy und `contao:migrate`, und derselbe Fall, gegen den
         * RootCurrencyMigration ihre eigenen Prüfungen stellt.
         *
         * Gemessen: Ohne diesen Test überlebt ein `?? 'EUR'` an dieser Stelle
         * die ganze Suite — und damit ein Shop, der während des Deploys
         * Pfundpreise in Euro anschreibt.
         */
        $root = $this->createMock(PageModel::class);
        $root->method('__get')->willReturnMap([['id', 5], ['type', 'root'], ['rootId', 5]]);
        $root->method('row')->willReturn(['id' => 5, 'consho_shop' => 7]);

        $resolver = new ShopResolver($this->createMock(ContaoFramework::class));

        self::assertSame('', $resolver->currencyOfPage($root));
    }

    public function testEinGeloeschterStartpunktLaesstDieWaehrungLeerImBackendUndAufDerKommandozeile(): void
    {
        /*
         * ⚠️ Der Name sagt bewusst, wo dieser Zweig vorkommt. Im Front-End
         * kommt er nicht vor: Dort wirft `loadDetails()` für eine Seite unter
         * keinem Startpunkt `NoRootPageFoundException`
         * (PageModel.php:1087), bevor currencyOfPage() überhaupt etwas
         * zurückgeben könnte. Das Doppel unten hat `loadDetails()` als
         * Nichtstuer und bildet damit den Backend- und CLI-Weg ab — dort
         * prüft Contao den Anfragekontext und wirft nicht.
         *
         * Die Zeile bleibt also richtig, aber sie belegt nicht, was ein
         * Besucher sieht.
         */
        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturn(null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $resolver = new ShopResolver($framework);

        self::assertSame('', $resolver->currencyOfPage($this->subpage(id: 12, rootId: 5)));
    }

    /**
     * Ein Startpunkt. `type` ist die echte Spalte, an der ShopResolver ihn
     * erkennt; `rootId` steht daneben, weil ein aufbereiteter Startpunkt beides
     * trägt.
     */
    private function page(int $id, int $rootId, int $shopId, string $currency = ''): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            ['type', 'root'],
            ['rootId', $rootId],
        ]);

        // consho_shop and consho_currency come from Consho's own DCA and are
        // therefore read from the row array, not as declared properties — see
        // ShopResolver.
        $page->method('row')->willReturn(['id' => $id, 'consho_shop' => $shopId, 'consho_currency' => $currency]);

        return $page;
    }

    /**
     * Eine Unterseite, wie der Frontend-Index sie weiterreicht: loadDetails()
     * ist gelaufen, `rootId` steht.
     *
     * Ihre Zeile trägt **kein** `consho_currency` — die Spalte wird nur am
     * Startpunkt gepflegt. Ein Doppel, das sie hier ungefragt herausgäbe, wäre
     * fähiger als das Original und machte jede Prüfung des Baumlaufs blind.
     */
    private function subpage(int $id, int $rootId): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([['id', $id], ['type', 'regular'], ['rootId', $rootId]]);
        $page->method('row')->willReturn(['id' => $id, 'consho_shop' => 0]);

        return $page;
    }

    /**
     * Dieselbe Unterseite, aber frisch aus findById(): Sie hat den Baum noch
     * nicht hochlaufen lassen und gibt `rootId` erst nach loadDetails() heraus
     * — genau wie das echte PageModel.
     *
     * Eine echte Closure mit `&$detailsLoaded`, keine Pfeilfunktion: die bindet
     * nur Kopien, und das Flag bliebe für immer false. Vorbild ist die
     * reparierte Fassung in AddToCartControllerTest.
     */
    private function unwalkedSubpage(int $id, int $rootId): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $detailsLoaded = false;

        $page->method('loadDetails')->willReturnCallback(
            static function () use (&$detailsLoaded): null {
                $detailsLoaded = true;

                return null;
            },
        );

        $page->method('__get')->willReturnCallback(
            static function (string $key) use ($id, $rootId, &$detailsLoaded): int|string|null {
                return match ($key) {
                    'id' => $id,
                    // `type` steht in der Zeile und gilt auch ohne Baumlauf —
                    // `rootId` erst danach. Genau dieser Unterschied ist der
                    // Grund, dass ShopResolver auf `type` prüft.
                    'type' => 'regular',
                    'rootId' => $detailsLoaded ? $rootId : null,
                    default => null,
                };
            },
        );
        $page->method('row')->willReturn(['id' => $id, 'consho_shop' => 0]);

        return $page;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function frameworkFinding(string $class, string $method, array $arguments, ?object $result): ContaoFramework
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->with($method, $arguments)->willReturn($result);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with($class)->willReturn($adapter);

        return $framework;
    }
}
