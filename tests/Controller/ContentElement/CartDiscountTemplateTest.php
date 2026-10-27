<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Block H (H-4): discount line and code form, rendered on the real cart
 * template — a tested service is not a tested page.
 */
class CartDiscountTemplateTest extends TestCase
{
    public function testRabattZwischensummeUndSumme(): void
    {
        $html = $this->render([
            'discount' => ['title' => 'Sommerrabatt', 'code' => 'Sommer24', 'freeShipping' => false, 'amount' => '−2,40 €'],
            'subtotal' => '24,00 €',
            'total' => '21,60 €',
            'discount_code' => 'Sommer24',
            'code_status' => 'applied',
        ]);

        self::assertStringContainsString('CONSHO.cart.discount.subtotal', $html);
        self::assertStringContainsString('Sommerrabatt <span class="consho-cart__code">(Sommer24)</span>', $html);
        self::assertStringContainsString('−2,40 €', $html);
        self::assertStringContainsString('21,60 €', $html);
        self::assertStringContainsString('CONSHO.cart.discount.status.applied[Sommer24|]', $html, 'die Meldung mit dem Code');
        self::assertStringContainsString('value="removeCode"', $html);
        self::assertStringNotContainsString('name="discount_code"', $html, 'ein Code zur Zeit');
    }

    public function testVersandkostenfreiOhneZwischensumme(): void
    {
        $html = $this->render([
            'discount' => ['title' => 'Gratisversand', 'code' => '', 'freeShipping' => true, 'amount' => '−0,00 €'],
            'subtotal' => '24,00 €',
            'total' => '24,00 €',
        ]);

        self::assertStringContainsString('CONSHO.cart.discount.freeShipping', $html);
        self::assertStringNotContainsString('CONSHO.cart.discount.subtotal', $html);
        self::assertStringNotContainsString('−0,00 €', $html);
    }

    public function testOhneCodeDasEingabefeld(): void
    {
        $html = $this->render([]);

        self::assertStringContainsString('name="discount_code"', $html);
        self::assertStringContainsString('value="code"', $html);
        self::assertStringNotContainsString('consho-cart__discount"', $html);
    }

    public function testDerFehlendeBetragStehtInDerMeldung(): void
    {
        $html = $this->render(['discount_code' => 'AB50', 'code_status' => 'minimumOrder', 'code_missing' => '4,00 €']);

        self::assertStringContainsString('CONSHO.cart.discount.status.minimumOrder[AB50|4,00 €]', $html);
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(array $vars): string
    {
        $twig = new Environment(new ArrayLoader([
            'cart' => (string) file_get_contents(__DIR__.'/../../../contao/templates/content_element/consho_cart.html.twig'),
            '@Contao/content_element/_base.html.twig' => '{% block content %}{% endblock %}',
        ]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => [] === $params ? $key : $key.'['.implode('|', array_map('strval', $params)).']'));
        $twig->addFunction(new TwigFunction('path', static fn (): string => '/x'));
        $twig->addGlobal('contao', ['request_token' => 'TOKEN']);

        return $twig->render('cart', $vars + [
            'lines' => [['itemId' => 1, 'articleId' => 1, 'title' => 'Rucksack', 'sku' => '', 'position' => 1, 'quantity' => '1', 'lineGross' => '24,00 €', 'terms' => null, 'renews' => '', 'stock' => '']],
            'unpriced' => [],
            'total' => '24,00 €',
            'showsNet' => false,
            'shipped' => true,
            'minimum_age' => 0,
            'checkout_url' => null,
            'account_required' => false,
            'is_empty' => false,
            'notice' => null,
            'discount_enabled' => true,
            'discount_code' => '',
            'code_status' => 'none',
            'code_missing' => '',
            'discount' => null,
        ]);
    }
}
