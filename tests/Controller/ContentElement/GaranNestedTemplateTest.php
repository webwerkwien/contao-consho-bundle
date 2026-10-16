<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;

/**
 * GA8 (review GA8, 3): das geschachtelte GARAN-Label durch die ganze Kette
 * der echten Vorlagen — Liste (`garanTiles[product.id]`) → Kachel → Teil-
 * vorlage. Wie StockTemplateTest, nur mit der Liste davor.
 */
class GaranNestedTemplateTest extends TestCase
{
    private const GARAN = [
        'years' => '2.5',
        'info' => 'https://europa.eu/youreurope/commercial-guarantee-durability/index.htm',
        'src' => '/files/garan/voll.png',
        'width' => 300,
        'height' => 316,
        'nested' => ['src' => '/files/garan/klein.svg', 'width' => 120, 'height' => 48],
    ];

    public function testDieKachelZeigtDasGeschachtelteUndOeffnetDasVolle(): void
    {
        $html = $this->listing([7 => self::GARAN]);

        self::assertMatchesRegularExpression('#<a href="/files/garan/voll\.png"[^>]*>\s*<img src="/files/garan/klein\.svg" alt="CONSHO\.garan\.alt\[2,5\]" width="120" height="48"#', $html, 'Link aufs volle, Bild des geschachtelten, Jahre mit Dezimalkomma');
        self::assertStringContainsString('href="https://europa.eu/youreurope/commercial-guarantee-durability/index.htm"', $html, 'der Link wie beim QR-Code (3.3.2)');
        self::assertSame(1, substr_count($html, 'consho-garan--nested'), 'nur an der Kachel, die eines hat');
    }

    public function testOhneGeschachteltesNichts(): void
    {
        self::assertStringNotContainsString('consho-garan', $this->listing([]));
    }

    /**
     * @param array<int, array<string, mixed>> $garanTiles
     */
    private function listing(array $garanTiles): string
    {
        $base = __DIR__.'/../../../contao/templates/';
        $twig = new Environment(new ArrayLoader([
            'list' => (string) file_get_contents($base.'content_element/consho_product_list.html.twig'),
            '@Contao/content_element/consho_product_tile.html.twig' => (string) file_get_contents($base.'content_element/consho_product_tile.html.twig'),
            '@Contao/consho/garan_nested.html.twig' => (string) file_get_contents($base.'consho/garan_nested.html.twig'),
            '@Contao/component/_figure.html.twig' => '{% block figure_component %}{% endblock %}',
            '@Contao/content_element/_base.html.twig' => '{% block content %}{% endblock %}',
        ]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => [] === $params ? ($key === 'MSC.decimalSeparator' ? ',' : $key) : $key.'['.implode(',', array_map('strval', $params)).']'));
        $twig->addFunction(new TwigFunction('figure', static fn (): null => null));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/add'));

        return $twig->render('list', [
            'configured' => true,
            'products' => [
                new ProductTile(7, 'Akkuschrauber', '', '', '10.0000', formattedPrice: '12,50 €'),
                new ProductTile(8, 'Seil', '', '', '10.0000', formattedPrice: '5,00 €'),
            ],
            'garanTiles' => $garanTiles,
            'shippedTiles' => [],
            'untaxedTiles' => [],
        ]);
    }
}
