<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Webwerkwien\ContaoConshoBundle\Controller\StockNoticeResult;

/**
 * Abnahme G-8: Direkt nach dem Anmelden standen zwei fast gleiche Sätze
 * untereinander — die Meldung des Knopfs und der Dauerhinweis.
 *
 * Gerendert wird der Abschnitt der echten Vorlage (vom Ergebnis bis vor das
 * Kaufformular), mit `trans` als Schlüssel-Echo und `path` als Routenname.
 */
class StockNoticeTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_product_detail.html.twig';

    public function testNachDemAnmeldenStehtDerHinweisNurEinmal(): void
    {
        $html = $this->render(StockNoticeResult::Subscribed);

        self::assertStringContainsString('CONSHO.stockNotice.result.subscribed', $html);
        self::assertStringNotContainsString('CONSHO.stockNotice.subscribed', $html);
        self::assertStringContainsString('CONSHO.stockNotice.unsubscribe', $html, 'abmelden bleibt möglich');
    }

    public function testBeimSpaeterenBesuchStehtDerDauerhinweis(): void
    {
        $html = $this->render(null);

        self::assertStringContainsString('CONSHO.stockNotice.subscribed', $html);
        self::assertStringNotContainsString('CONSHO.stockNotice.result.', $html);
    }

    private function render(StockNoticeResult|null $result): string
    {
        $source = (string) file_get_contents(self::TEMPLATE);
        $start = strpos($source, '{% if stockNoticeResult|default(null) %}');
        $end = strpos($source, '{% if purchase.articleId %}');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $twig = new Environment(new ArrayLoader(['part' => substr($source, $start, $end - $start)]), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (string $route): string => '/'.$route));

        return $twig->render('part', [
            'stockNoticeResult' => $result,
            'stockNotice' => ['state' => 'subscribed', 'articleId' => 8, 'main' => 8, 'pageId' => 123],
        ]);
    }
}
