<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;

/**
 * Text check on the template "Versandkosten" (shipping costs, E-6), like
 * CartTemplateTest: no rendering — this project has no Twig environment for
 * it — but every key the template translates exists in both languages, and
 * with exactly as many `%s` as it passes values.
 */
class ShippingCostsTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_shipping_costs.html.twig';

    /** Keys the template fills with one value; every other one with none. */
    private const WITH_VALUE = ['countries', 'upTo', 'limits', 'limitLength', 'limitGirth', 'limitWeight'];

    public function testJederSchluesselStehtInBeidenSprachen(): void
    {
        $source = (string) file_get_contents(self::TEMPLATE);
        preg_match_all("/\\(t ~ '(\\w+)'\\)|'(codWithFee|cod)'/", $source, $matches);
        $keys = array_values(array_unique(array_filter([...$matches[1], ...$matches[2]])));

        self::assertContains('none', $keys);
        self::assertContains('codWithFee', $keys);

        foreach (['de', 'en'] as $language) {
            $GLOBALS['TL_LANG'] = [];
            include __DIR__.'/../../../contao/languages/'.$language.'/default.php';

            foreach ($keys as $key) {
                $text = $GLOBALS['TL_LANG']['CONSHO']['shippingCosts'][$key] ?? null;

                self::assertIsString($text, $language.': '.$key);
                self::assertSame(\in_array($key, self::WITH_VALUE, true) ? 1 : 0, substr_count($text, '%s'), $language.': '.$key);
            }

            self::assertIsArray($GLOBALS['TL_LANG']['CTE']['consho_shipping_costs'] ?? null, $language);
        }

        unset($GLOBALS['TL_LANG']);
    }

    /**
     * Like "Meine Bestellungen" (my orders, D-4): themes that stack tables on
     * narrow screens label every cell with `data-cellheadline`. Without it an
     * empty ":" stood in front of every cell.
     */
    public function testJedeZelleTraegtIhreSpaltenueberschrift(): void
    {
        $source = (string) file_get_contents(self::TEMPLATE);

        self::assertSame(substr_count($source, '<td'), substr_count($source, '<td data-cellheadline="'));
    }

    public function testKeinRohesDezimalInDerVorlage(): void
    {
        // Weights and amounts come formatted from ShippingCostsView
        // (MoneyFormatter) — "2.0000 kg" would be the trailing zeros MySQL/MariaDB
        // returns for a decimal column and SQLite does not.
        self::assertStringNotContainsString('|number_format', (string) file_get_contents(self::TEMPLATE));
    }
}
