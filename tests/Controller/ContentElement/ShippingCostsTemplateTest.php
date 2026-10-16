<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;

/**
 * Textprüfung an der Vorlage „Versandkosten" (E-6), wie CartTemplateTest: kein
 * Rendering — dafür gibt es in diesem Projekt keine Twig-Umgebung —, aber
 * jeder Schlüssel, den die Vorlage übersetzt, steht in beiden Sprachen, und
 * mit genau so vielen `%s`, wie sie Werte übergibt.
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
     * Wie „Meine Bestellungen" (D-4): Themes, die Tabellen auf schmalen
     * Bildschirmen stapeln, beschriften jede Zelle mit `data-cellheadline`.
     * Ohne es stand bei der Abnahme ein leeres „:" vor jeder Zelle.
     */
    public function testJedeZelleTraegtIhreSpaltenueberschrift(): void
    {
        $source = (string) file_get_contents(self::TEMPLATE);

        self::assertSame(substr_count($source, '<td'), substr_count($source, '<td data-cellheadline="'));
    }

    public function testKeinRohesDezimalInDerVorlage(): void
    {
        // Gewichte und Beträge kommen formatiert aus ShippingCostsView
        // (MoneyFormatter) — „2.0000 kg" wäre Fall 30 der Prüfungsnotiz.
        self::assertStringNotContainsString('|number_format', (string) file_get_contents(self::TEMPLATE));
    }
}
