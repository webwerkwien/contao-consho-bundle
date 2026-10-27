<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Pure text checks on the Twig **file**, no rendering: for an `@Contao`
 * fragment with blocks, `attrs()`, `path()` and a domain-specific `trans`,
 * this project has no Twig environment setup — for the same reason
 * ProductDetailControllerTest builds its FragmentTemplate "without Twig and
 * without a container" (see its class comment).
 *
 * What these tests do NOT show: that the file renders without errors, that
 * two purchase boxes for the same product carry different ids in the actual
 * DOM, or that the translation keys used hit existing entries (except for
 * `request_token`/`REQUEST_TOKEN`, for which a string search is exactly
 * right).
 *
 * What they achieve beyond a plain string search: for/id and
 * aria-describedby/id pairs are compared as expressions that belong together
 * (regex capture of both sides, then equality), not just checked for
 * isolated occurrence. A `for` that points nowhere, or two instances with a
 * colliding id, would thus stand out, provided they show in the source
 * (e.g. different variables for the same purpose).
 */
class ProductDetailPurchaseTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_product_detail.html.twig';

    public function testDasFormularTraegtKeinAnfrageToken(): void
    {
        // C11: the catalogue page is cached as shared. A token in it would be
        // that of a foreign visitor — exactly the case the dedicated route exists
        // for. {{ request_token }} and REQUEST_TOKEN must not appear here.
        $template = file_get_contents(self::TEMPLATE);

        self::assertStringNotContainsString('request_token', $template);
        self::assertStringNotContainsString('REQUEST_TOKEN', $template);
    }

    public function testDasFormularPostetAnDieEigeneRoute(): void
    {
        $template = file_get_contents(self::TEMPLATE);

        self::assertStringContainsString("path('consho_cart_add')", $template);
        self::assertStringContainsString('method="post"', $template);
    }

    public function testOhneArtikelIdGibtEsKeinFormular(): void
    {
        // As long as a variant is still to be chosen, purchase.articleId is null —
        // a button that does nothing is worse than none.
        $template = file_get_contents(self::TEMPLATE);

        self::assertMatchesRegularExpression('/{%\s*if\s+purchase\.articleId\s*%}/', $template);
    }

    public function testDieSeiteWirdMitgeschickt(): void
    {
        // From it the route derives the shop and the return address.
        $template = file_get_contents(self::TEMPLATE);

        self::assertStringContainsString('name="page"', $template);
    }

    /**
     * Two purchase boxes for the same product (main area and sidebar) would
     * have had the same id with `purchase.articleId` as the only part of the
     * id — the label of the second box then points nowhere. The id must
     * therefore follow the same pattern as `safety_heading_id` further down in
     * the same file: the content element (`data.id`) makes it unique per
     * instance, not the product.
     */
    public function testMengenfeldIdIstJeInhaltselementEindeutig(): void
    {
        $template = file_get_contents(self::TEMPLATE);

        [$forExpression, $idExpression] = $this->extractLabelForAndFieldId($template);

        self::assertSame(
            $forExpression,
            $idExpression,
            'Label und Eingabefeld müssen exakt denselben Ausdruck für die Id verwenden.',
        );

        $variableName = $this->extractSetVariableName($forExpression);

        self::assertMatchesRegularExpression(
            '/\{%\s*set\s+'.preg_quote($variableName, '/').'\s*=\s*[\'"]consho-quantity-[\'"]\s*~\s*data\.id\|default\([\'"][\'"]\)\s*%\}/',
            $template,
            'Die Id-Variable muss wie safety_heading_id aus data.id gebildet werden, nicht aus purchase.articleId.',
        );

        // Counter-check of the pattern itself: safety_heading_id follows exactly the same form.
        self::assertMatchesRegularExpression(
            '/\{%\s*set\s+safety_heading_id\s*=\s*[\'"]consho-safety-[\'"]\s*~\s*data\.id\|default\([\'"][\'"]\)\s*%\}/',
            $template,
        );
    }

    /**
     * A minimum quantity or a step above 1 is a real restriction which, via
     * keyboard/screen reader, would otherwise only show after a rejected
     * submission (13.2). The notice text must therefore be attached to the
     * field via aria-describedby as soon as one of the two values applies.
     */
    public function testMengenhinweisIstUeberAriaDescribedbyVerknuepft(): void
    {
        $template = file_get_contents(self::TEMPLATE);

        self::assertStringContainsString('CONSHO.cart.quantityHint', $template);

        // The trigger threshold must be compared numerically: as a Twig string
        // only "0" is false, "0.0000" would be true and trigger the notice for no reason.
        self::assertMatchesRegularExpression('/purchase\.minPurchase\|default\(0\)\s*>\s*1/', $template);
        self::assertMatchesRegularExpression('/purchase\.purchaseSteps\|default\(0\)\s*>\s*1/', $template);

        [$ariaExpression, $helpParagraphIdExpression] = $this->extractAriaDescribedbyAndHelpId($template);

        self::assertSame(
            $ariaExpression,
            $helpParagraphIdExpression,
            'aria-describedby muss exakt auf die Id des Hinweistextes zeigen.',
        );
    }

    /**
     * Only the string "0" is false in Twig — "0.0000" is true and would have
     * produced step="0.0000", invalid HTML to which the browser silently
     * reacts with a step of 1.
     */
    public function testSchrittweiteWirdNumerischGeprueft(): void
    {
        $template = file_get_contents(self::TEMPLATE);

        self::assertDoesNotMatchRegularExpression(
            '/\{%\s*if\s+purchase\.purchaseSteps\s*%\}step=/',
            $template,
            'Die alte reine Twig-Wahrheitsprüfung darf vor step= nicht mehr stehen.',
        );
        self::assertMatchesRegularExpression(
            '/\{%\s*if\s+purchase\.purchaseSteps\|default\(0\)\s*>\s*0\s*%\}step=/',
            $template,
        );
    }

    /**
     * The same care two lines further up — and for the same reason.
     * `tl_consho_product.minPurchase` carries `rgxp => 'digit'` and allows
     * `0`; `"0.0000"` is **true** in Twig (only the string "0" is false), and
     * `|default()` only applies to null, false or empty. What came out was
     * `value="0.0000" min="0.0000"` — a click without a change ended in
     * InvalidQuantity, with the message "Diese Menge ist nicht möglich" (this
     * quantity is not possible) for a number the shop itself had set.
     */
    public function testDieMindestmengeWirdNumerischGeprueft(): void
    {
        $markup = $this->markup();

        self::assertDoesNotMatchRegularExpression(
            '/\{%\s*if\s+purchase\.minPurchase\s*%\}\s*min=/',
            $markup,
            'Die alte reine Twig-Wahrheitsprüfung darf vor min= nicht mehr stehen.',
        );
        self::assertDoesNotMatchRegularExpression(
            '/value="\{\{\s*purchase\.minPurchase\|default\(/',
            $markup,
            '|default() greift nicht bei "0.0000" — der Vorgabewert darf nicht daran hängen.',
        );

        // A single numeric comparison carries both places, in the form of the
        // thresholds directly above.
        self::assertMatchesRegularExpression(
            '/\{%\s*set\s+quantity_has_min\s*=\s*purchase\.minPurchase\|default\(0\)\s*>\s*0\s*%\}/',
            $markup,
        );
        self::assertMatchesRegularExpression(
            '/value="\{\{\s*quantity_has_min\s*\?\s*quantity_min_display\s*:\s*\'1\'\s*\}\}"/',
            $markup,
        );
        self::assertMatchesRegularExpression(
            '/\{%\s*if\s+quantity_has_min\s*%\}\s*min="\{\{\s*quantity_min_display\s*\}\}"/',
            $markup,
        );
    }

    /**
     * 🔴 Seen in the front end: the quantity field of every product showed
     * "1,0000".
     *
     * `minPurchase` and `purchaseSteps` are `decimal(10,4)` and come out of the
     * database as "1.0000". The shortening already existed — but it only ran
     * over the notice text, with the explicit reasoning that `value` was "an
     * HTML attribute and not display text". That is exactly the mistake:
     * **the content of `value` is what is shown in the field.**
     *
     * ⚠️ And why eleven tests passed over it: they check the SOURCE of the
     * template by regex, never its output. That `value="{{ … }}"` sits in the
     * right place says nothing about what ends up there. That is why this test
     * really computes the shortening — with the same Twig filter the template
     * uses.
     *
     * @dataProvider mengen
     */
    public function testDieMengeStehtImFeldWieManSieSchreibt(string $gespeichert, string $erwartet): void
    {
        $twig = new Environment(new ArrayLoader([
            // Word for word the chain from the template.
            'q' => "{{ wert|trim(characterMask='0', side='right')|trim(characterMask='.', side='right') }}",
        ]));

        self::assertSame($erwartet, $twig->render('q', ['wert' => $gespeichert]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mengen(): iterable
    {
        yield 'die Eins im Mengenfeld' => ['1.0000', '1'];
        yield 'Zehnerschritte' => ['10.0000', '10'];
        // ⚠️ The reason for stopping at the dot: trimming "0" and "." together
        // would eat the zeros of "100" as well.
        yield 'die Null am Ende bleibt' => ['100.0000', '100'];
        yield 'Meterware' => ['0.1000', '0.1'];
        yield 'halbe Kilo' => ['2.5000', '2.5'];
        yield 'volle Genauigkeit bleibt stehen' => ['0.0125', '0.0125'];
    }

    /**
     * The template **without** its Twig comments — as in CartTemplateTest and
     * MiniCartControllerTest.
     *
     * ⚠️ Not cosmetic: the explanatory comment above the quantity field quotes
     * the rule and in doing so spells out exactly the forms searched for here.
     * Measured on the raw file, a "must not occur" search would also read the
     * explanation and would be green because it measures something other than
     * claimed.
     */
    public function testDerAbgelehnteKaufWirdAmKaufknopfGemeldet(): void
    {
        // C-3c: the reason comes from the address and is shown here, no longer
        // on the cart page. The key is composed from `notice.value`, so it is not
        // spelled out anywhere — the same convention as in the cart, so that
        // both use the same sentences.
        self::assertMatchesRegularExpression(
            '/\(\s*"CONSHO\.cart\.notice\."\s*~\s*notice\.value\s*\)\|trans/',
            $this->markup(),
        );
    }

    public function testDieMeldungHaengtNichtAmVorhandenenKaufknopf(): void
    {
        // A variant that is still to be chosen has no purchase button
        // (purchase.articleId is null) — a rejection must still be readable.
        // The message therefore stands BEFORE the condition, not inside it.
        // Checked by the positions in the source, because that is exactly the
        // design decision.
        $markup = $this->markup();

        $meldung = strpos($markup, '{% if notice %}');
        $knopf = strpos($markup, '{% if purchase.articleId %}');

        self::assertIsInt($meldung, 'die Meldung muss es geben');
        self::assertIsInt($knopf, 'die Kaufknopf-Bedingung muss es geben');
        self::assertLessThan($knopf, $meldung, 'die Meldung steht außerhalb und vor der Kaufknopf-Bedingung');
    }

    /**
     * Block G (G7): the state stands before the purchase button condition —
     * with "derzeit nicht verfügbar" (currently unavailable) purchase.articleId
     * is null, the sentence must still be there. The key is composed from the
     * state.
     */
    public function testDerBestandStehtVorDemKaufknopf(): void
    {
        $markup = $this->markup();

        $bestand = strpos($markup, '{% if stock|default(null) %}');
        $knopf = strpos($markup, '{% if purchase.articleId %}');

        self::assertIsInt($bestand);
        self::assertIsInt($knopf);
        self::assertLessThan($knopf, $bestand);
        self::assertMatchesRegularExpression('/\(\s*"CONSHO\.stock\."\s*~\s*stock\.state\s*\)\|trans/', $markup);
    }

    public function testDieModellwahlStehtImKaufformularUnterDemNamenDerRoute(): void
    {
        // Abo-2 (P10): AddToCartController reads `subscriptionRule`. If the
        // choice stood outside the form or under another name, every click would
        // arrive as a one-time purchase — exactly the error from Abo-1, only quieter.
        $markup = $this->markup();

        $formStart = strpos($markup, '<form class="consho-purchase"');
        // The end of THIS form: since Block G the notice and confirmation
        // forms stand before it (G12, G15).
        $formEnd = false === $formStart ? false : strpos($markup, '</form>', $formStart);
        $radio = strpos($markup, 'type="radio" name="subscriptionRule"');

        self::assertIsInt($formStart);
        self::assertIsInt($formEnd);
        self::assertIsInt($radio, 'die Auswahl muss es geben');
        self::assertGreaterThan($formStart, $radio);
        self::assertLessThan($formEnd, $radio);
        self::assertStringContainsString('value="{{ plan.rule }}"', $markup);
    }

    public function testDieModellwahlIstEineBeschrifteteGruppe(): void
    {
        $markup = $this->markup();

        self::assertMatchesRegularExpression('/<fieldset class="consho-purchase__plans">\s*<legend>/', $markup);
        // for and id from the same variable, the conditions via
        // aria-describedby on their button.
        self::assertStringContainsString('id="{{ plan_id }}"', $markup);
        self::assertStringContainsString('<label for="{{ plan_id }}">', $markup);
        self::assertStringContainsString('aria-describedby="{{ plan_id }}-terms"', $markup);
        self::assertStringContainsString('id="{{ plan_id }}-terms"', $markup);
        // Unique per content element, like the quantity field.
        self::assertMatchesRegularExpression("/set plan_id = 'consho-plan-' ~ data\\.id/", $markup);
    }

    private function markup(): string
    {
        $contents = file_get_contents(self::TEMPLATE);

        self::assertIsString($contents);

        return (string) preg_replace('/\{#.*?#\}/s', '', $contents);
    }

    /**
     * The rest of the file always uses a class to name the element that
     * carries the value (e.g. rich_text_component calls). The class therefore
     * belongs on the input field, not on the label.
     */
    public function testMengenklasseSitztAufDemFeldNichtAufDerBeschriftung(): void
    {
        $template = file_get_contents(self::TEMPLATE);

        $labelTag = $this->extractQuantityLabelTag($template);
        $inputTag = $this->extractQuantityInputTag($template);

        self::assertStringNotContainsString('consho-purchase__quantity"', $labelTag);
        self::assertStringContainsString('class="consho-purchase__quantity"', $inputTag);
    }

    /**
     * @return array{0: string, 1: string} [for expression, id expression]
     */
    private function extractLabelForAndFieldId(string $template): array
    {
        self::assertMatchesRegularExpression(
            '/<label\s+for="([^"]+)">\s*\{\{\s*"CONSHO\.cart\.quantity"/',
            $template,
            'Die Beschriftung des Mengenfelds wurde nicht im erwarteten Aufbau gefunden.',
        );
        preg_match('/<label\s+for="([^"]+)">\s*\{\{\s*"CONSHO\.cart\.quantity"/', $template, $labelMatch);

        self::assertMatchesRegularExpression(
            '/<input\s+[^>]*\bid="([^"]+)"[^>]*\btype="number"/s',
            $template,
            'Das Mengenfeld wurde nicht im erwarteten Aufbau gefunden.',
        );
        preg_match('/<input\s+[^>]*\bid="([^"]+)"[^>]*\btype="number"/s', $template, $inputMatch);

        return [$labelMatch[1], $inputMatch[1]];
    }

    private function extractSetVariableName(string $expression): string
    {
        self::assertMatchesRegularExpression('/^\{\{\s*(\w+)\s*\}\}$/', $expression);
        preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $expression, $match);

        return $match[1];
    }

    /**
     * @return array{0: string, 1: string} [aria-describedby expression, notice text id expression]
     */
    private function extractAriaDescribedbyAndHelpId(string $template): array
    {
        // The one of the quantity, not the first of the file: since Abo-2 the
        // model choice with its own aria-describedby stands before it. (The input
        // field itself cannot be captured cleanly by regex — `> 0` in a condition
        // ends every `[^>]*`.)
        self::assertMatchesRegularExpression('/aria-describedby="(\{\{\s*quantity[^"]*)"/', $template);
        preg_match('/aria-describedby="(\{\{\s*quantity[^"]*)"/', $template, $ariaMatch);

        self::assertMatchesRegularExpression('/<p\s+class="consho-purchase__quantity-help"\s+id="([^"]+)"/', $template);
        preg_match('/<p\s+class="consho-purchase__quantity-help"\s+id="([^"]+)"/', $template, $helpMatch);

        return [$ariaMatch[1], $helpMatch[1]];
    }

    private function extractQuantityLabelTag(string $template): string
    {
        self::assertMatchesRegularExpression('/<label[^>]*>\s*\{\{\s*"CONSHO\.cart\.quantity"/', $template);
        preg_match('/<label[^>]*>/', $template, $match);

        return $match[0];
    }

    private function extractQuantityInputTag(string $template): string
    {
        self::assertMatchesRegularExpression('/<input\s[^>]*\btype="number"[^>]*>/s', $template);
        preg_match('/<input\s[^>]*\btype="number"[^>]*>/s', $template, $match);

        return $match[0];
    }
}
