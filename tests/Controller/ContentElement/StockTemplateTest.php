<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;

/**
 * Block G (G7): the stock on the tile, rendered on the real template —
 * like TaxNoticeTemplateTest.
 */
class StockTemplateTest extends TestCase
{
    public function testAusverkauftOhneKaufknopf(): void
    {
        $html = $this->tile('unavailable');

        self::assertStringContainsString('CONSHO.stock.unavailable', $html);
        self::assertStringNotContainsString('consho-tile__purchase', $html);
    }

    public function testNurNochWenigeMitKaufknopf(): void
    {
        $html = $this->tile('few');

        self::assertStringContainsString('CONSHO.stock.few', $html);
        self::assertStringContainsString('consho-tile__purchase', $html);
    }

    public function testOhneBestandSagtDieKachelNichts(): void
    {
        $html = $this->tile('');

        self::assertStringNotContainsString('CONSHO.stock.', $html);
        self::assertStringContainsString('consho-tile__purchase', $html);
    }

    /**
     * @dataProvider sprachen
     */
    public function testJederZustandHatSeinenSatz(string $language): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/default.php';

        foreach (StockState::cases() as $state) {
            self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['stock'][$state->value] ?? null, $state->value);
        }

        foreach (['onlyLeft', 'availableFrom'] as $key) {
            self::assertStringContainsString('%s', (string) ($GLOBALS['TL_LANG']['CONSHO']['stock'][$key] ?? ''), $key);
        }

        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['cart']['notice']['out_of_stock'] ?? null);
        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['checkout']['purchase']['stockShort'] ?? null);
        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['checkout']['problem']['stockShort'] ?? null);

        unset($GLOBALS['TL_LANG']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function sprachen(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    private function tile(string $stock): string
    {
        $twig = new Environment(new ArrayLoader([
            'tile' => (string) file_get_contents(__DIR__.'/../../../contao/templates/content_element/consho_product_tile.html.twig'),
            '@Contao/component/_figure.html.twig' => '{% block figure_component %}{% endblock %}',
        ]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('figure', static fn (): null => null));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/add'));

        return $twig->render('tile', [
            'product' => new ProductTile(7, 'Wanderstock', '', '', '10.0000', formattedPrice: '12,50 €', isBuyable: true, stock: $stock),
            'tilePurchase' => true,
        ]);
    }
}
