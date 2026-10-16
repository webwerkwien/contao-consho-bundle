<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\ProductListController;

/**
 * Nur die Übersetzung der gespeicherten Kachelvorlage in einen Twig-Pfad.
 *
 * getResponse() selbst hat keinen Unit-Test (FragmentTemplate ist final) —
 * geprüft in der Abnahme. Diese eine Regel aber entscheidet, welche Datei die
 * Liste einbindet, und ein falscher Pfad reißt die ganze Seite mit.
 */
class ProductListControllerTest extends TestCase
{
    public function testEmptyMeansTheBundledTile(): void
    {
        self::assertSame('', ProductListController::tileTemplate(''));
    }

    public function testAVariantOfTheTileBecomesATwigPath(): void
    {
        // So speichert TileTemplateOptionsListener seit B-5: die volle Kennung.
        self::assertSame(
            '@Contao/content_element/consho_product_tile/special.html.twig',
            ProductListController::tileTemplate('content_element/consho_product_tile/special'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAVariantOfTheTile(): iterable
    {
        // Die Form, die Controller::getTemplateGroup() geliefert hätte, hätte die
        // Maske sich öffnen lassen.
        yield 'alte Form ohne Präfix' => ['consho_product_tile/special'];

        // Die Grundvorlage ist „leer" — die Liste nimmt sie ohnehin.
        yield 'Grundvorlage' => ['content_element/consho_product_tile'];
        yield 'Präfix ohne Namen' => ['content_element/consho_product_tile/'];

        // Eine fremde Vorlage bekäme `product` und würde etwas anderes rendern.
        yield 'fremdes Element' => ['content_element/text/special'];
        yield 'ähnlicher Name' => ['content_element/consho_product_tiles/special'];
    }

    #[DataProvider('notAVariantOfTheTile')]
    public function testAnythingElseFallsBackToTheBundledTile(string $stored): void
    {
        self::assertSame('', ProductListController::tileTemplate($stored));
    }

    public function testTheCacheLivesUntilTheEarlierOfPinAndProduct(): void
    {
        // B-7b: Anheftung und Produkt verkürzen beide, das frühere gilt.
        self::assertSame(300, ProductListController::earliest(600, 300));
        self::assertSame(600, ProductListController::earliest(600, null));
        self::assertSame(300, ProductListController::earliest(null, 300));
        self::assertNull(ProductListController::earliest(null, null));
    }
}
