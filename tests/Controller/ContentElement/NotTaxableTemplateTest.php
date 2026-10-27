<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;

/**
 * WG-7: the non-taxable voucher and the payments on the order page —
 * rendered on the real sections of the templates, with the real German
 * texts.
 */
class NotTaxableTemplateTest extends TestCase
{
    private const DIR = __DIR__.'/../../../contao/templates/content_element/';

    /** @var array<string, mixed>|null */
    private array|null $saved = null;

    protected function setUp(): void
    {
        $this->saved = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        $GLOBALS['TL_LANG'] = $this->saved;
    }

    public function testDerWarenkorbSagtBeimGutscheinNichtsVonDerSteuer(): void
    {
        $part = $this->part('consho_cart.html.twig', '<span class="consho-cart__tax">', '</span>');

        self::assertStringNotContainsString('USt', $this->render($part, ['untaxed' => true, 'shipped' => false]));
        self::assertStringContainsString('zzgl. Versandkosten', $this->render($part, ['untaxed' => true, 'shipped' => true]));
        self::assertStringNotContainsString('USt', $this->render($part, ['untaxed' => true, 'shipped' => true]));
        self::assertStringContainsString('inkl. USt.', $this->render($part, ['untaxed' => false, 'shipped' => false]));
    }

    public function testDieProduktseiteLaesstDenSatzWeg(): void
    {
        $part = $this->part('consho_product_detail.html.twig', '{% if not untaxed|default(false) or (shipped ?? true) %}', '{% endif %}');

        self::assertSame('', trim($this->render($part, ['untaxed' => true, 'shipped' => false, 'price' => ['showsNet' => false]])));
        $shipped = $this->render($part, ['untaxed' => true, 'shipped' => true, 'price' => ['showsNet' => false]]);
        self::assertStringContainsString('zzgl. Versandkosten', $shipped);
        self::assertStringNotContainsString('USt', $shipped);
        self::assertStringContainsString('inkl. USt.', $this->render($part, ['untaxed' => false, 'shipped' => false, 'price' => ['showsNet' => false]]));
    }

    public function testDieKasseNenntNurDieGesamtsumme(): void
    {
        $part = $this->part('consho_checkout.html.twig', '{% if state.reverseCharge %}', "{% endif %}\n\n                                {#- Wertgutscheine");
        // Up to the endif of this block, without the comment after it.
        $part = substr($part, 0, (int) strrpos($part, '{% endif %}') + \strlen('{% endif %}'));
        $vars = ['state' => ['reverseCharge' => false], 'net_total' => '25,00 €', 'total' => '25,00 €'];

        $untaxed = $this->render($part, ['untaxed' => true] + $vars);
        self::assertStringContainsString('<dt>Gesamtsumme</dt>', $untaxed);
        self::assertStringNotContainsString('USt', $untaxed);
        self::assertStringContainsString('inkl. USt.', $this->render($part, ['untaxed' => false] + $vars));
    }

    public function testDieBestellseiteSagtNichtSteuerbarUndZeigtDieZahlungen(): void
    {
        $part = $this->part('consho_order.html.twig', '<table class="consho-order__totals">', '</table>');
        $order = ['netTotal' => '25,00 €', 'taxTotal' => '0,00 €', 'grandTotal' => '25,00 €', 'taxes' => [['notTaxable' => true]]];

        $html = $this->render($part, ['order' => $order, 'payments' => [], 'outstanding' => '']);
        self::assertStringContainsString('nicht steuerbar', $html);
        self::assertStringNotContainsString('0,00 €', $html);
        self::assertStringNotContainsString('Nettosumme', $html);

        // Partial cover: voucher, rest open; a chargeback names the voucher.
        $mixed = ['taxes' => [['notTaxable' => false]]] + $order;
        $html = $this->render($part, ['order' => $mixed, 'payments' => [
            ['label' => 'Gutschein …ZZ3P', 'refund' => false, 'amount' => '16,00 €'],
            ['label' => 'Gutschein …ZZ3P', 'refund' => true, 'amount' => '−6,90 €'],
        ], 'outstanding' => '9,70 €']);

        self::assertStringContainsString('(Gutschein …ZZ3P)</th><td data-cellheadline="Bereits bezahlt (Gutschein …ZZ3P)">16,00 €', $html);
        self::assertMatchesRegularExpression('/Erstattet \(Gutschein …ZZ3P\)/', $html);
        self::assertStringContainsString('<strong>9,70 €</strong>', $html);
    }

    private function part(string $file, string $from, string $to): string
    {
        $source = str_replace("\r\n", "\n", (string) file_get_contents(self::DIR.$file));
        $start = strpos($source, $from);
        self::assertNotFalse($start, $from);
        $end = strpos($source, $to, $start + \strlen($from));
        self::assertNotFalse($end, $to);

        return substr($source, $start, $end - $start + \strlen($to));
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(string $part, array $vars): string
    {
        $twig = new Environment(new ArrayLoader(['part' => $part]));
        $twig->addFilter(new TwigFilter('trans', static function (string $id, array $parameters = []): string {
            $value = $GLOBALS['TL_LANG'];

            foreach (explode('.', $id) as $key) {
                if (!\is_array($value) || !isset($value[$key])) {
                    return $id;
                }

                $value = $value[$key];
            }

            return \is_string($value) ? $value : $id;
        }));

        return $twig->render('part', $vars);
    }
}
