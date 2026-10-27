<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Checkout\PurchaseProblem;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherStatus;

/**
 * WG-5 (E3): the vouchers on the review page, rendered on the real section
 * of the template, and their texts in both languages.
 */
class VoucherTemplateTest extends TestCase
{
    private const CHECKOUT = __DIR__.'/../../../contao/templates/content_element/consho_checkout.html.twig';

    public function testGutscheineStehenUnterDerSummeMitDemRest(): void
    {
        $html = $this->review([
            ['code' => '…MDSN', 'status' => 'usable', 'balance' => '50,00 €', 'amount' => '−30,00 €', 'after' => '20,00 €', 'pays' => true],
            ['code' => '…HHHH', 'status' => 'blocked', 'balance' => '10,00 €', 'amount' => '0,00 €', 'after' => '10,00 €', 'pays' => false],
            ['code' => '…ZZZZ', 'status' => 'usable', 'balance' => '5,00 €', 'amount' => '0,00 €', 'after' => '5,00 €', 'pays' => false],
        ]);

        self::assertStringContainsString('<dt>CONSHO.cart.voucher.applied[…MDSN]</dt>', $html);
        self::assertStringContainsString('−30,00 €', $html);
        self::assertStringContainsString('CONSHO.cart.voucher.after[…MDSN|20,00 €]', $html);
        // Whatever cannot pay says why; whatever is merely not needed stays silent.
        self::assertStringContainsString('CONSHO.cart.voucher.status.blocked[…HHHH|10,00 €]', $html);
        self::assertStringNotContainsString('…ZZZZ', $html);
        self::assertStringContainsString('<dt>CONSHO.cart.voucher.payable</dt>', $html);
        self::assertStringContainsString('<strong>16,00 €</strong>', $html);
        self::assertStringContainsString('href="/warenkorb"', $html);
    }

    public function testOhneGutscheineKeinRest(): void
    {
        self::assertStringNotContainsString('payable', $this->review([]));
    }

    #[DataProvider('languages')]
    public function testDieTexteStehenInDerSprachdatei(string $language): void
    {
        $saved = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/'.$language.'/default.php';

        self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['checkout']['purchase'][PurchaseProblem::VoucherChanged->value] ?? null);

        foreach ([CartNotice::VoucherUnknown, CartNotice::TooManyVouchers] as $notice) {
            self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['cart']['notice'][$notice->value] ?? null, $notice->value);
        }

        foreach (VoucherStatus::cases() as $status) {
            self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['cart']['voucher']['status'][$status->value] ?? null, $status->value);
        }

        foreach (['code', 'apply', 'remove', 'removeLabel', 'later', 'notForVouchers', 'applied', 'payable', 'covered', 'change', 'payment', 'after'] as $key) {
            self::assertIsString($GLOBALS['TL_LANG']['CONSHO']['cart']['voucher'][$key] ?? null, $key);
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
     * @param list<array<string, mixed>> $vouchers
     */
    private function review(array $vouchers): string
    {
        $source = (string) file_get_contents(self::CHECKOUT);
        $start = strpos($source, '{% if review_vouchers|default([]) is not empty %}');
        $end = strpos($source, '</dl>', (int) $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);

        $twig = new Environment(new ArrayLoader(['part' => substr($source, $start, $end - $start)]));
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => [] === $params ? $key : $key.'['.implode('|', array_map('strval', $params)).']'));

        return $twig->render('part', ['review_vouchers' => $vouchers, 'payable' => '16,00 €', 'cart_url' => '/warenkorb']);
    }
}
