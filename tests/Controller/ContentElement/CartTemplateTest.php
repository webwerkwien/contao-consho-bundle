<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;

/**
 * Pure text checks on the Twig **file**, no rendering — for the same reason
 * as in ProductDetailPurchaseTest: for an `@Contao` fragment with blocks,
 * `attrs()` and a domain-specific `trans`, this project has no Twig
 * environment setup.
 *
 * What these tests do NOT show:
 *
 * - that the file renders without errors;
 * - that the generated HTML tree is actually valid — what is checked is that
 *   every `<form>` in the source stands between `<td>` and `</td>`, not the
 *   result of an HTML parser;
 * - that a browser, on a click on "Übernehmen" (apply), really sends only
 *   the fields of **one** line (that is the reasoning behind the design,
 *   not its proof);
 * - that two cart elements on one page carry different ids in the actual
 *   DOM;
 * - that Contao's translator resolves the keys **at runtime** — what is
 *   checked is that they are in `contao/languages/{de,en}/default.php`, not
 *   the resolution itself.
 *
 * What they achieve beyond a plain string search:
 *
 * - The `<form>` blocks are cut out one by one and counted per block (one
 *   `item`, at most one `quantity`, one request token). Exactly the error of
 *   an early draft — one form across the whole table with one `item` per
 *   line — would thus stand out, because a single block would then contain
 *   several `name="item"`.
 * - The language files are really loaded, and every `CONSHO.…` key in the
 *   template must have an entry in **both**; for the notice, additionally
 *   each of the five `CartNotice` cases.
 */
class CartTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_cart.html.twig';

    /**
     * The error this design deliberately avoids: a browser sends **all**
     * fields of the form the button belongs to. A form across the whole table
     * would deliver the line id of every line, and the server would read the
     * last one.
     */
    public function testJedesFormularTraegtGenauEinePositionsId(): void
    {
        $forms = $this->forms();

        self::assertNotSame([], $forms, 'Die Vorlage enthält überhaupt kein Formular.');

        foreach ($forms as $index => $form) {
            self::assertSame(
                1,
                substr_count($form, 'name="item"'),
                \sprintf('Formular %d trägt nicht genau ein name="item".', $index),
            );
        }
    }

    /**
     * The same for the quantity: the line id on the button alone would have
     * solved only half the problem — otherwise the quantity fields of all
     * other lines still travel along, and the server would again receive the
     * last one.
     */
    public function testKeinFormularTraegtMehrAlsEinMengenfeld(): void
    {
        foreach ($this->forms() as $index => $form) {
            self::assertLessThanOrEqual(
                1,
                substr_count($form, 'name="quantity"'),
                \sprintf('Formular %d trägt mehr als ein name="quantity".', $index),
            );
        }
    }

    /**
     * A `<form>` as a child of `<table>`, `<tbody>` or `<tr>` is invalid HTML;
     * the parser moves it in front of the table and the fields then stand in
     * no form at all. `<td>` takes flow content, so every form belongs
     * entirely inside one cell.
     */
    public function testJedesFormularStehtInEinerTabellenzelle(): void
    {
        preg_match('/<table\b.*?<\/table>/s', $this->markup(), $table);
        $markup = $table[0] ?? '';

        $insideCells = 0;

        foreach ($this->cells() as $cell) {
            $insideCells += substr_count($cell, '<form');
        }

        self::assertGreaterThan(0, substr_count($markup, '<form'));
        self::assertSame(
            substr_count($markup, '<form'),
            $insideCells,
            'Es steht ein <form> außerhalb einer <td>-Zelle — als Kind von <table>/<tr> wäre es ungültig.',
        );
    }

    public function testJedesFormularTraegtDasAnfrageToken(): void
    {
        // Unlike the route "in den Warenkorb" (add to cart, C11): the cart page is
        // private anyway, the token in it belongs to the visitor who reads it.
        foreach ($this->allForms() as $index => $form) {
            self::assertStringContainsString(
                'name="REQUEST_TOKEN"',
                $form,
                \sprintf('Formular %d hat kein Anfrage-Token.', $index),
            );
        }
    }

    public function testDasTokenKommtAusDerNichtVeraltetenVariablen(): void
    {
        // The Twig variable `request_token` is deprecated since Contao 5.3 and
        // reports that on every call; `contao.request_token` is its successor.
        $template = $this->template();

        self::assertStringContainsString('{{ contao.request_token }}', $template);
        self::assertDoesNotMatchRegularExpression('/\{\{\s*request_token\s*\}\}/', $template);
    }

    /**
     * Several quantity fields on one page, and the element can stand twice on
     * the same page. The id therefore needs **both**: the id of the content
     * element (`data.id`) against the second instance and the line id against
     * the neighbouring line. The article id would be neither.
     */
    public function testDieMengenfeldIdTraegtElementUndPosition(): void
    {
        $template = $this->markup();

        self::assertMatchesRegularExpression(
            '/\{%\s*set\s+field_prefix\s*=\s*[\'"]consho-cart-[\'"]\s*~\s*data\.id\|default\([\'"][\'"]\)\s*%\}/',
            $template,
            'Der Id-Vorsatz muss wie quantity_id in consho_product_detail.html.twig aus data.id gebildet werden.',
        );

        self::assertMatchesRegularExpression(
            '/\{%\s*set\s+quantity_id\s*=\s*field_prefix\s*~\s*[\'"][^\'"]*[\'"]\s*~\s*line\.itemId\s*%\}/',
            $template,
            'Die Feld-Id muss zusätzlich die Positions-Id tragen.',
        );

        [$forExpression, $idExpression] = $this->labelForAndFieldId($template);

        self::assertSame(
            $forExpression,
            $idExpression,
            'Beschriftung und Eingabefeld müssen exakt denselben Ausdruck für die Id verwenden.',
        );
    }

    /**
     * 0 removes the line. A `type="number"` with `min` would forbid the
     * browser exactly the input the server is supposed to react to.
     */
    public function testDasMengenfeldVerbietetDieNullNicht(): void
    {
        $field = $this->quantityInput($this->markup());

        self::assertStringContainsString('type="text"', $field);
        self::assertStringContainsString('inputmode="decimal"', $field);
        self::assertStringNotContainsString('type="number"', $field);
        self::assertStringNotContainsString('min=', $field);
    }

    /**
     * `CartView::isEmpty()` is **false** when only lines without a price are
     * left. The empty message must therefore hang on `is_empty` and not on
     * `lines`, otherwise a line the visitor has to remove to get any further
     * disappears.
     */
    public function testDieLeermeldungHaengtAnIsEmptyNichtAnDenBepreistenZeilen(): void
    {
        $template = $this->markup();

        self::assertMatchesRegularExpression('/\{%\s*if\s+is_empty\s*%\}/', $template);
        self::assertDoesNotMatchRegularExpression('/\{%\s*if\s+lines\s+is\s+empty\s*%\}/', $template);
    }

    /**
     * A line without a price must stand there with its title and be removable —
     * without a title the visitor does not know which button to press.
     */
    public function testUnbepreisbareZeilenWerdenMitTitelUndEntfernenGezeigt(): void
    {
        $template = $this->markup();

        self::assertMatchesRegularExpression('/\{%\s*for\s+line\s+in\s+unpriced\s*%\}/', $template);

        $unpricedBlock = $this->unpricedBlock($template);

        self::assertStringContainsString('line_title', $unpricedBlock);
        self::assertStringContainsString('value="remove"', $unpricedBlock);
        self::assertStringContainsString('name="item"', $unpricedBlock);
    }

    /**
     * A title that stays empty (the product is deleted) must not leave an empty
     * cell — then a "Entfernen" (remove) would stand there without reference.
     */
    public function testEinFehlenderTitelWirdErsetzt(): void
    {
        $template = $this->markup();

        self::assertSame(
            2,
            preg_match_all('/\{%\s*set\s+line_title\s*=\s*line\.title\s*\?:\s*"CONSHO\.cart\.unknownArticle"/', $template),
            'Beide Schleifen müssen denselben Ersatztitel verwenden.',
        );
    }

    /**
     * The title alone does not name a line. CartTitles deliberately returns the
     * title of the main product, so two variants have the same name there —
     * and two lines without any title both fall back to the same replacement
     * text. Every line therefore needs a feature of its own: the article
     * number, otherwise the position in the cart.
     */
    public function testJedeZeileTraegtEinEigenesMerkmal(): void
    {
        $template = $this->markup();

        self::assertSame(
            2,
            preg_match_all('/\{%\s*set\s+line_mark\s*=\s*line\.sku\s*\?\s*"CONSHO\.detail\.sku"\|trans\([^)]*\)\s*~\s*\' \'\s*~\s*line\.sku\s*:\s*\'#\'\s*~\s*line\.position\s*%\}/', $template),
            'Beide Schleifen müssen dasselbe Merkmal bilden: Artikelnummer, sonst Position.',
        );

        self::assertSame(
            2,
            preg_match_all('/\{%\s*set\s+line_name\s*=\s*line_title\s*~\s*\' \(\'\s*~\s*line_mark\s*~\s*\'\)\'\s*%\}/', $template),
            'Beide Schleifen müssen den Namen aus Titel UND Merkmal bilden.',
        );
    }

    /**
     * The feature stands visibly in the line, not only in the accessible
     * name: two lines with the same name cannot be told apart by eye either,
     * and the product page shows the article number openly as well
     * (`consho_product_detail.html.twig`, `<dl>`).
     */
    public function testDasMerkmalStehtSichtbarInDerZeile(): void
    {
        self::assertSame(
            2,
            preg_match_all('/<span class="consho-cart__mark">\{\{ line_mark \}\}<\/span>/', $this->markup()),
            'Beide Schleifen müssen das Merkmal sichtbar ausgeben.',
        );
    }

    public function testDieAbobedingungenStehenInDerArtikelzelle(): void
    {
        // Abo-2 (A10): visible in the line, for the priced lines.
        $markup = $this->markup();

        $cell = strpos($markup, '<td data-cellheadline="{{ head_article }}">');
        $terms = strpos($markup, '{{ line.terms }}');
        $quantityCell = strpos($markup, '<td data-cellheadline="{{ head_quantity }}">');

        self::assertIsInt($cell);
        self::assertIsInt($terms, 'die Bedingungen müssen ausgegeben werden');
        self::assertIsInt($quantityCell);
        self::assertGreaterThan($cell, $terms);
        self::assertLessThan($quantityCell, $terms);
    }

    public function testDerKontohinweisStehtVorDemWegZurKasse(): void
    {
        // P12: before the click, not after it.
        $markup = $this->markup();

        $hint = strpos($markup, '{% if account_required|default(false) %}');
        $checkout = strpos($markup, '{% if checkout_url|default(\'\') %}');

        self::assertIsInt($hint);
        self::assertIsInt($checkout);
        self::assertLessThan($checkout, $hint);
    }

    /**
     * Labels and buttons are read out outside their line — "Entfernen
     * Wanderstock" (remove hiking pole) twice is the same name for a screen
     * reader. The field id is unique per line, but an id is not a name.
     */
    public function testDerZugaenglicheNameTraegtDasMerkmalMit(): void
    {
        $template = $this->markup();

        self::assertMatchesRegularExpression(
            '/"CONSHO\.cart\.quantityOf"\|trans\(\[line_name\]/',
            $template,
            'Die Beschriftung des Mengenfelds muss den Namen mit Merkmal einsetzen.',
        );

        self::assertSame(
            3,
            preg_match_all('/<span class="invisible"> \{\{ line_name \}\}<\/span>/', $template),
            'Alle drei Knöpfe (übernehmen, entfernen, entfernen ohne Preis) brauchen den Namen mit Merkmal.',
        );

        // The bare title must no longer stand in any accessible name —
        // otherwise exactly the spot this is about would remain.
        self::assertDoesNotMatchRegularExpression('/<span class="invisible"> \{\{ line_title \}\}<\/span>/', $template);
        self::assertDoesNotMatchRegularExpression('/"CONSHO\.cart\.quantityOf"\|trans\(\[line_title\]/', $template);
    }

    /**
     * Amounts come fully formatted from MoneyFormatter. A currency the template
     * appends itself would stand in the same place in every language — in
     * English it belongs in front.
     */
    public function testDieVorlageHaengtKeineWaehrungSelbstAn(): void
    {
        $template = $this->template();

        self::assertStringNotContainsString('{{ currency }}', $template);
    }

    public function testJederFesteUebersetzungsschluesselStehtInBeidenSprachdateien(): void
    {
        preg_match_all('/"(CONSHO\.[A-Za-z.]+)"\|trans/', $this->markup(), $matches);

        $keys = array_values(array_unique($matches[1]));

        self::assertNotSame([], $keys, 'Die Vorlage übersetzt gar nichts — dann prüft dieser Test nichts.');

        foreach (['de', 'en'] as $locale) {
            $labels = $this->labels($locale);

            foreach ($keys as $key) {
                self::assertIsString(
                    $this->label($labels, $key),
                    \sprintf('%s fehlt in contao/languages/%s/default.php.', $key, $locale),
                );
            }
        }
    }

    /**
     * Four cases, since `other_shop` was dropped with C-3b. The key is composed
     * in the template from `notice.value`, so it is not spelled out anywhere —
     * a missing case would otherwise only be noticed by the visitor who is
     * shown the raw key.
     */
    public function testJedeNotizHatInBeidenSprachenEinenSatz(): void
    {
        self::assertMatchesRegularExpression(
            '/\(\s*"CONSHO\.cart\.notice\."\s*~\s*notice\.value\s*\)\|trans/',
            $this->markup(),
        );

        foreach (['de', 'en'] as $locale) {
            $labels = $this->labels($locale);

            foreach (CartNotice::cases() as $case) {
                self::assertIsString(
                    $this->label($labels, 'CONSHO.cart.notice.'.$case->value),
                    \sprintf('CONSHO.cart.notice.%s fehlt in contao/languages/%s/default.php.', $case->value, $locale),
                );
            }
        }
    }

    /**
     * The language file into an array, without damaging a running
     * $GLOBALS['TL_LANG'] of other tests.
     *
     * @return array<string, mixed>
     */
    private function labels(string $locale): array
    {
        $backup = $GLOBALS['TL_LANG'] ?? null;
        $GLOBALS['TL_LANG'] = [];

        try {
            include __DIR__.'/../../../contao/languages/'.$locale.'/default.php';

            /** @var array<string, mixed> $loaded */
            $loaded = $GLOBALS['TL_LANG'];
        } finally {
            if (null === $backup) {
                unset($GLOBALS['TL_LANG']);
            } else {
                $GLOBALS['TL_LANG'] = $backup;
            }
        }

        return $loaded;
    }

    /**
     * Like Contao's MessageCatalogue: walk down the dot key level by level. An
     * intermediate node (an array) is not a label.
     *
     * @param array<string, mixed> $labels
     */
    private function label(array $labels, string $key): string|null
    {
        $item = $labels;

        foreach (explode('.', $key) as $part) {
            if (!\is_array($item) || !isset($item[$part])) {
                return null;
            }

            $item = $item[$part];
        }

        return \is_array($item) ? null : (string) $item;
    }

    /**
     * The template **without** its Twig comments.
     *
     * ⚠️ This is not cosmetic. The comment block at the top explains the design
     * and in doing so spells out `<form>` and `<td>`. Measured on the raw file,
     * the first `<form …>…</form>` therefore began **in the comment** and ran
     * to the first real `</form>` — the count per form concerned a range that
     * does not exist in the markup at all. The tests were green and measured
     * something other than claimed; it only showed when a mutation (a `<form>`
     * as a child of `<tr>`) did not turn them red.
     *
     * Deliberately open: if `#}` stood in a string **outside** a comment, the
     * expression would cut at the wrong place. That does not occur in this
     * file, and a Twig parser just for this would be more machinery than
     * benefit.
     */
    private function markup(): string
    {
        return (string) preg_replace('/\{#.*?#\}/s', '', $this->template());
    }

    /**
     * @return list<string>
     */
    private function forms(): array
    {
        // The forms of the lines — inside the table. The discount code's forms
        // (block H, H-4) stand below it and belong to no line.
        preg_match('/<table\b.*?<\/table>/s', $this->markup(), $table);
        preg_match_all('/<form\b.*?<\/form>/s', $table[0] ?? '', $matches);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function allForms(): array
    {
        preg_match_all('/<form\b.*?<\/form>/s', $this->markup(), $matches);

        return $matches[0];
    }

    /**
     * @return list<string>
     */
    private function cells(): array
    {
        preg_match_all('/<td\b.*?<\/td>/s', $this->markup(), $matches);

        return $matches[0];
    }

    private function quantityInput(string $template): string
    {
        self::assertMatchesRegularExpression('/<input\s+id="[^"]+"[^>]*\bname="quantity"[^>]*>/s', $template);
        preg_match('/<input\s+id="[^"]+"[^>]*\bname="quantity"[^>]*>/s', $template, $match);

        return $match[0];
    }

    private function unpricedBlock(string $template): string
    {
        self::assertMatchesRegularExpression('/\{%\s*for\s+line\s+in\s+unpriced\s*%\}(.*?)\{%\s*endfor\s*%\}/s', $template);
        preg_match('/\{%\s*for\s+line\s+in\s+unpriced\s*%\}(.*?)\{%\s*endfor\s*%\}/s', $template, $match);

        return $match[1];
    }

    /**
     * @return array{0: string, 1: string} [for expression, id expression]
     */
    private function labelForAndFieldId(string $template): array
    {
        self::assertMatchesRegularExpression('/<label\s+class="invisible"\s+for="([^"]+)">/', $template);
        preg_match('/<label\s+class="invisible"\s+for="([^"]+)">/', $template, $labelMatch);

        self::assertMatchesRegularExpression('/<input\s+id="([^"]+)"[^>]*\bname="quantity"/s', $template);
        preg_match('/<input\s+id="([^"]+)"[^>]*\bname="quantity"/s', $template, $inputMatch);

        return [$labelMatch[1], $inputMatch[1]];
    }

    private function template(): string
    {
        $contents = file_get_contents(self::TEMPLATE);

        self::assertIsString($contents);

        return $contents;
    }
}
