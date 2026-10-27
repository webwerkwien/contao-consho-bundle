<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Webwerkwien\ContaoConshoBundle\Cart\Discount\CodeStatus;
use Webwerkwien\ContaoConshoBundle\Checkout\PurchaseProblem;

/**
 * Block H (H-4): the discount line on the review page, rendered on the real
 * section of the template, and its texts in both languages.
 */
class CheckoutDiscountTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_checkout.html.twig';

    public function testDerRabattStehtMitCodeUndBetrag(): void
    {
        $html = $this->render(['title' => 'Herbstrabatt', 'code' => 'Herbst', 'amount' => '−4,60 €', 'freeShipping' => false, 'status' => 'applied']);

        self::assertStringContainsString('<dt>Herbstrabatt (Herbst)</dt>', $html);
        self::assertStringContainsString('<dd>−4,60 €</dd>', $html);
        self::assertStringNotContainsString('consho-checkout__code-status', $html);
    }

    public function testVersandkostenfrei(): void
    {
        $html = $this->render(['title' => 'Gratisversand', 'code' => '', 'amount' => '0,00 €', 'freeShipping' => true, 'status' => 'applied']);

        self::assertStringContainsString('<dt>Gratisversand</dt>', $html);
        self::assertStringContainsString('CONSHO.cart.discount.freeShipping', $html);
    }

    public function testEinCodeDerHierNichtGiltSagtWarum(): void
    {
        // W5: the guest's address has already redeemed the code.
        $html = $this->render(['title' => '', 'code' => '', 'amount' => '0,00 €', 'freeShipping' => false, 'status' => 'exhausted'], 'Herbst');

        self::assertStringNotContainsString('<dt>', $html);
        self::assertStringContainsString('CONSHO.cart.discount.status.exhausted[Herbst|]', $html);
    }

    public function testDerFehlendeMindestwertStehtDa(): void
    {
        // H: the missing amount as the second placeholder.
        $html = $this->render(['title' => '', 'code' => '', 'amount' => '0,00 €', 'freeShipping' => false, 'status' => 'minimumOrder', 'missing' => '1,00 €'], 'Herbst');

        self::assertStringContainsString('CONSHO.cart.discount.status.minimumOrder[Herbst|1,00 €]', $html);
    }

    #[DataProvider('languages')]
    public function testDieTexteStehenInDerSprachdatei(string $language): void
    {
        $saved = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/default.php';

        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['checkout']['purchase'][PurchaseProblem::DiscountExhausted->value] ?? null);

        foreach (CodeStatus::cases() as $status) {
            self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['cart']['discount']['status'][$status->value] ?? null, $status->value);
        }

        foreach (['code', 'apply', 'remove', 'subtotal', 'freeShipping'] as $key) {
            self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['cart']['discount'][$key] ?? null, $key);
        }

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

    /**
     * @param array<string, mixed> $discount
     */
    private function render(array $discount, string $code = ''): string
    {
        $source = (string) file_get_contents(self::TEMPLATE);
        $start = strpos($source, '{% if review_discount|default(null) %}');
        $end = strpos($source, '{% if review_payment|default(null) %}');
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $twig = new Environment(new ArrayLoader(['part' => substr($source, $start, $end - $start)]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => [] === $params ? $key : $key.'['.implode('|', array_map('strval', $params)).']'));

        return $twig->render('part', ['review_discount' => $discount, 'discount_code' => $code]);
    }
}
