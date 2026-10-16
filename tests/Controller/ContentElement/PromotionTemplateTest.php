<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;

/**
 * Block H (H-2): Störer und Streichpreis, an den echten Vorlagen gerendert —
 * und nur, wo es einen Bezugspreis gibt (B).
 */
class PromotionTemplateTest extends TestCase
{
    private const TEMPLATES = __DIR__.'/../../../contao/templates/content_element/';

    public function testDieKachelZeigtStoererUndStreichpreis(): void
    {
        $html = $this->tile(new ProductTile(7, 'Essig', '', '', '9.6', formattedPrice: '9,60 €', compare: '12', formattedCompare: '12,00 €'));

        self::assertStringContainsString('CONSHO.promotion.badge', $html);
        self::assertStringContainsString('<del>12,00 €</del> CONSHO.promotion.reference', $html);
    }

    public function testDieKachelOhneBezugspreisZeigtNichts(): void
    {
        $html = $this->tile(new ProductTile(7, 'Essig', '', '', '9.6', formattedPrice: '9,60 €', compare: '12'));

        self::assertStringNotContainsString('CONSHO.promotion', $html, 'compare allein reicht nicht — formatiert wird nur, was gezeigt werden darf');
        self::assertStringNotContainsString('<del>', $html);
    }

    public function testDieProduktseiteZeigtStoererUndStreichpreis(): void
    {
        $html = $this->detail(['formatted' => '9,60 €', 'compare' => '12,00 €']);

        self::assertStringContainsString('CONSHO.promotion.badge', $html);
        self::assertStringContainsString('<del>12,00 €</del> CONSHO.promotion.reference', $html);
    }

    public function testDieProduktseiteOhneBezugspreisZeigtNichts(): void
    {
        $html = $this->detail(['formatted' => '9,60 €']);

        self::assertStringNotContainsString('CONSHO.promotion', $html);
        self::assertStringNotContainsString('<del>', $html);
    }

    #[DataProvider('languages')]
    public function testDieTexteStehenInDerSprachdatei(string $language): void
    {
        $saved = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/default.php';

        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['promotion']['badge'] ?? null);
        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['promotion']['reference'] ?? null);

        $GLOBALS['TL_LANG'] = $saved;
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function languages(): iterable
    {
        yield 'de' => ['de'];
        yield 'en' => ['en'];
    }

    private function tile(ProductTile $product): string
    {
        $twig = $this->twig(['tile' => (string) file_get_contents(self::TEMPLATES.'consho_product_tile.html.twig')]);

        return $twig->render('tile', ['product' => $product, 'showsNet' => false]);
    }

    /**
     * Der Preisblock der echten Vorlage, ohne den Rest der Seite.
     *
     * @param array<string, mixed> $price
     */
    private function detail(array $price): string
    {
        $source = (string) file_get_contents(self::TEMPLATES.'consho_product_detail.html.twig');
        $start = strpos($source, '{% block consho_price %}');
        $end = strpos($source, '{% endblock %}', (int) $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $twig = $this->twig(['price' => substr($source, $start, $end - $start).'{% endblock %}']);

        return $twig->render('price', ['price' => $price + ['isFrom' => false, 'showsNet' => false, 'basePrice' => null, 'scale' => []]]);
    }

    /**
     * @param array<string, string> $templates
     */
    private function twig(array $templates): Environment
    {
        $twig = new Environment(new ArrayLoader($templates + ['@Contao/component/_figure.html.twig' => '{% block figure_component %}{% endblock %}']));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('figure', static fn (): null => null));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/add'));

        return $twig;
    }
}
