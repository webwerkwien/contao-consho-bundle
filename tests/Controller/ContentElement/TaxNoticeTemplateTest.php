<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;

/**
 * Abnahme DL, Fund 2: „zzgl. Versandkosten" nur, wo etwas versandt wird —
 * an der echten Kachelvorlage gerendert, nicht am Quelltext gesucht.
 */
class TaxNoticeTemplateTest extends TestCase
{
    public function testDieKachelSagtVersandNurBeiWare(): void
    {
        self::assertStringContainsString('CONSHO.priceTaxNotice<', $this->tile(true, false));
        self::assertStringContainsString('CONSHO.priceTaxOnly<', $this->tile(false, false));
        self::assertStringContainsString('CONSHO.priceTaxOnlyNet<', $this->tile(false, true));
        self::assertStringContainsString('CONSHO.priceTaxNotice<', $this->tile(null, false), 'ohne Angabe wie bisher');
    }

    /** Abo-2b, P89: „auch im Abo" unter einem Einmalkauf-Preis, sonst nicht. */
    public function testAuchImAbo(): void
    {
        $html = $this->tile(true, false, new ProductTile(7, 'E-Book', '', '', '9.0000', formattedPrice: '9,00 €', alsoSubscription: true));
        self::assertStringContainsString('CONSHO.tile.alsoSubscription', $html);
        self::assertLessThan(strpos($html, 'CONSHO.tile.alsoSubscription'), strpos($html, 'CONSHO.priceTaxNotice'), 'der Steuerhinweis gehört direkt zum Preis (Abnahme Abo-2b)');
        self::assertStringNotContainsString('CONSHO.tile.alsoSubscription', $this->tile(true, false));
    }

    /**
     * @dataProvider sprachen
     */
    public function testDieSchluesselStehenInDerSprachdatei(string $language): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/default.php';

        foreach (['priceTaxNotice', 'priceTaxNoticeNet', 'priceTaxOnly', 'priceTaxOnlyNet'] as $key) {
            self::assertIsString($GLOBALS['TL_LANG']['CONSHO'][$key] ?? null, $key);
        }

        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['tile']['alsoSubscription'] ?? null, 'tile.alsoSubscription');

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

    private function tile(bool|null $shipped, bool $net, ProductTile|null $product = null): string
    {
        $twig = new Environment(new ArrayLoader([
            'tile' => (string) file_get_contents(__DIR__.'/../../../contao/templates/content_element/consho_product_tile.html.twig'),
            '@Contao/component/_figure.html.twig' => '{% block figure_component %}{% endblock %}',
        ]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('figure', static fn (): null => null));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/add'));

        $vars = ['product' => $product ?? new ProductTile(7, 'Beratungspaket', '', '', '10.0000', formattedPrice: '12,50 €'), 'showsNet' => $net];

        if (null !== $shipped) {
            $vars['shipped'] = $shipped;
        }

        return $twig->render('tile', $vars);
    }
}
