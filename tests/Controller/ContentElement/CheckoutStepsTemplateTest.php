<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/**
 * The step list of the checkout, rendered on the real section of the
 * template: an earlier step is a link back, the current and later ones are
 * not.
 */
class CheckoutStepsTemplateTest extends TestCase
{
    private const FILE = __DIR__.'/../../../contao/templates/content_element/consho_checkout.html.twig';

    public function testFruehereSchritteSindVerlinkt(): void
    {
        $html = $this->render(3, 4);

        self::assertStringContainsString('<a href="?consho_step=1">CONSHO.checkout.step.address</a>', $html);
        self::assertStringContainsString('<a href="?consho_step=2">CONSHO.checkout.step.shipping</a>', $html);
        self::assertStringNotContainsString('consho_step=3', $html);
        self::assertStringNotContainsString('consho_step=4', $html);
        self::assertMatchesRegularExpression('/<li aria-current="step">\s*CONSHO\.checkout\.step\.payment\s*<\/li>/', $html);
    }

    public function testAufDemErstenSchrittKeinLink(): void
    {
        self::assertStringNotContainsString('<a ', $this->render(1, 4));
    }

    private function render(int $step, int $count): string
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(self::FILE));
        $from = '<nav class="consho-checkout__steps"';
        $start = strpos($source, $from);
        self::assertNotFalse($start);
        $end = strpos($source, '</nav>', $start);
        self::assertNotFalse($end);

        $twig = new Environment(new ArrayLoader(['part' => substr($source, $start, $end - $start + \strlen('</nav>'))]), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $id): string => $id));

        return $twig->render('part', [
            'step' => $step,
            'step_count' => $count,
            'step_labels' => ['address', 'shipping', 'payment', 'review'],
        ]);
    }
}
