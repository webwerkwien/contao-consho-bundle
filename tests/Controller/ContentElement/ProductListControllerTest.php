<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\ProductListController;

/**
 * Only the translation of the stored tile template into a Twig path.
 *
 * getResponse() itself has no unit test (FragmentTemplate is final) — it is
 * checked in the browser. But this one rule decides which file the list
 * includes, and a wrong path takes the whole page down with it.
 */
class ProductListControllerTest extends TestCase
{
    public function testEmptyMeansTheBundledTile(): void
    {
        self::assertSame('', ProductListController::tileTemplate(''));
    }

    public function testAVariantOfTheTileBecomesATwigPath(): void
    {
        // This is how TileTemplateOptionsListener stores it since B-5: the full identifier.
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
        // The form Controller::getTemplateGroup() would have delivered, had the
        // mask been able to open.
        yield 'alte Form ohne Präfix' => ['consho_product_tile/special'];

        // The base template is "empty" — the list takes it anyway.
        yield 'Grundvorlage' => ['content_element/consho_product_tile'];
        yield 'Präfix ohne Namen' => ['content_element/consho_product_tile/'];

        // A foreign template would get `product` and render something else.
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
        // B-7b: pin and product both shorten, the earlier one applies.
        self::assertSame(300, ProductListController::earliest(600, 300));
        self::assertSame(600, ProductListController::earliest(600, null));
        self::assertSame(300, ProductListController::earliest(null, 300));
        self::assertNull(ProductListController::earliest(null, null));
    }
}
