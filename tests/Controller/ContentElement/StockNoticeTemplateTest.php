<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Webwerkwien\ContaoConshoBundle\Controller\StockNoticeResult;

/**
 * G-8: right after logging in, two almost identical sentences stood one
 * below the other — the button's message and the permanent notice.
 *
 * What is rendered is the section of the real template (from the result to
 * just before the purchase form), with `trans` as a key echo and `path` as
 * the route name.
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
