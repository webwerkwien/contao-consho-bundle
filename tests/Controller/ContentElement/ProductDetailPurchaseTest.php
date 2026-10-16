<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Reine Textprüfungen an der Twig-**Datei**, kein Rendering: Für ein
 * `@Contao`-Fragment mit Blöcken, `attrs()`, `path()` und domänenspezifischem
 * `trans` gibt es in diesem Projekt keinen Twig-Umgebungsaufbau — aus
 * demselben Grund baut auch ProductDetailControllerTest sein FragmentTemplate
 * "ohne Twig und ohne Container" (siehe dessen Klassenkommentar).
 *
 * Was diese Tests NICHT zeigen: dass die Datei fehlerfrei rendert, dass zwei
 * Kaufboxen für dasselbe Produkt im tatsächlichen DOM unterschiedliche Ids
 * tragen, oder dass die verwendeten Übersetzungsschlüssel existierende
 * Einträge treffen (bis auf `request_token`/`REQUEST_TOKEN`, wofür eine
 * Zeichenkettensuche genau richtig ist).
 *
 * Was sie über bloße Zeichenkettensuche hinaus leisten: für/id- und
 * aria-describedby/id-Paare werden als zusammengehörige Ausdrücke verglichen
 * (Regex-Erfassung beider Seiten, dann Gleichheit), nicht nur auf isoliertes
 * Vorkommen geprüft. Ein `for`, das ins Leere zeigt, oder zwei Instanzen mit
 * kollidierender Id fielen damit auf, sofern sie sich im Quelltext
 * ausdrücken (z. B. verschiedene Variablen für denselben Zweck).
 */
class ProductDetailPurchaseTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_product_detail.html.twig';

    public function testDasFormularTraegtKeinAnfrageToken(): void
    {
        // C11: die Katalogseite ist geteilt gecacht. Ein Token darin wäre das
        // eines fremden Besuchers — genau der Fall, für den die eigene Route da
        // ist. {{ request_token }} und REQUEST_TOKEN dürfen hier nicht stehen.
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
        // Solange eine Variante zu wählen ist, ist purchase.articleId null —
        // ein Knopf, der nichts tut, ist schlimmer als keiner.
        $template = file_get_contents(self::TEMPLATE);

        self::assertMatchesRegularExpression('/{%\s*if\s+purchase\.articleId\s*%}/', $template);
    }

    public function testDieSeiteWirdMitgeschickt(): void
    {
        // Aus ihr leitet die Route den Shop und den Rücksprung ab.
        $template = file_get_contents(self::TEMPLATE);

        self::assertStringContainsString('name="page"', $template);
    }

    /**
     * Zwei Kaufboxen für dasselbe Produkt (Hauptbereich und Seitenleiste)
     * hätten mit `purchase.articleId` als einzigem Bestandteil der Id
     * dieselbe Id — die Beschriftung der zweiten Box zeigt dann ins Leere.
     * Die Id muss deshalb dasselbe Muster tragen wie `safety_heading_id`
     * weiter unten in derselben Datei: das Inhaltselement (`data.id`) macht
     * sie je Instanz eindeutig, nicht das Produkt.
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

        // Gegenprobe des Musters selbst: safety_heading_id folgt exakt derselben Form.
        self::assertMatchesRegularExpression(
            '/\{%\s*set\s+safety_heading_id\s*=\s*[\'"]consho-safety-[\'"]\s*~\s*data\.id\|default\([\'"][\'"]\)\s*%\}/',
            $template,
        );
    }

    /**
     * Mindestmenge oder Schrittweite über 1 sind eine echte Einschränkung, die
     * per Tastatur/Screenreader sonst erst nach einem abgelehnten Absenden
     * auffällt (13.2). Der Hinweistext muss deshalb per aria-describedby am
     * Feld hängen, sobald einer der beiden Werte greift.
     */
    public function testMengenhinweisIstUeberAriaDescribedbyVerknuepft(): void
    {
        $template = file_get_contents(self::TEMPLATE);

        self::assertStringContainsString('CONSHO.cart.quantityHint', $template);

        // Die Auslöseschwelle muss numerisch verglichen werden: Als Twig-Zeichenkette
        // ist nur "0" falsch, "0.0000" wäre wahr und triggerte den Hinweis grundlos.
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
     * Befund 4: Nur die Zeichenkette "0" ist in Twig falsch — "0.0000" ist
     * wahr und hätte step="0.0000" erzeugt, ungültiges HTML, auf das der
     * Browser stillschweigend mit Schrittweite 1 reagiert.
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
     * Befund 9: Dieselbe Sorgfalt zwei Zeilen weiter oben — und aus demselben
     * Grund. `tl_consho_product.minPurchase` trägt `rgxp => 'digit'` und lässt
     * `0` zu; `"0.0000"` ist in Twig **wahr** (nur die Zeichenkette "0" ist
     * falsch), und `|default()` greift nur bei null, false oder leer. Heraus
     * kam `value="0.0000" min="0.0000"` — ein Klick ohne Änderung landete in
     * InvalidQuantity, mit der Meldung „Diese Menge ist nicht möglich" für
     * eine Zahl, die der Shop selbst vorgegeben hatte.
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

        // Ein einziger numerischer Vergleich trägt beide Stellen, in der Form
        // der Schwellen direkt darüber.
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
     * 🔴 Von Michael im Frontend gesehen (2026-09-21): Das Mengenfeld jedes
     * Produkts stand auf „1,0000".
     *
     * `minPurchase` und `purchaseSteps` sind `decimal(10,4)` und kommen als
     * „1.0000" aus der Datenbank. Die Kürzung gab es schon — sie lief aber nur
     * über den Hinweistext, mit der ausdrücklichen Begründung, `value` sei
     * „ein HTML-Attribut und kein Anzeigetext". Genau das ist der Irrtum:
     * **Der Inhalt von `value` ist das, was im Feld steht.**
     *
     * ⚠️ Und warum elf Tests darüber hinweggingen: Sie prüfen den QUELLTEXT
     * der Vorlage per Regex, nie ihre Ausgabe. Dass `value="{{ … }}"` an der
     * richtigen Stelle steht, sagt nichts darüber, was am Ende dort steht.
     * Deshalb rechnet dieser Test die Kürzung wirklich aus — mit demselben
     * Twig-Filter, den die Vorlage benutzt.
     *
     * @dataProvider mengen
     */
    public function testDieMengeStehtImFeldWieManSieSchreibt(string $gespeichert, string $erwartet): void
    {
        $twig = new Environment(new ArrayLoader([
            // Wortgleich die Kette aus der Vorlage.
            'q' => "{{ wert|trim(characterMask='0', side='right')|trim(characterMask='.', side='right') }}",
        ]));

        self::assertSame($erwartet, $twig->render('q', ['wert' => $gespeichert]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mengen(): iterable
    {
        yield 'die Eins, die Michael gesehen hat' => ['1.0000', '1'];
        yield 'Zehnerschritte' => ['10.0000', '10'];
        // ⚠️ Der Grund, warum am Punkt gestoppt wird: „0" und „." zusammen zu
        // trimmen fräße die Null von „100" mit.
        yield 'die Null am Ende bleibt' => ['100.0000', '100'];
        yield 'Meterware' => ['0.1000', '0.1'];
        yield 'halbe Kilo' => ['2.5000', '2.5'];
        yield 'volle Genauigkeit bleibt stehen' => ['0.0125', '0.0125'];
    }

    /**
     * Die Vorlage **ohne** ihre Twig-Kommentare — wie in CartTemplateTest und
     * MiniCartControllerTest.
     *
     * ⚠️ Nicht kosmetisch: Der Erklärkommentar über dem Mengenfeld zitiert die
     * Regel und schreibt dabei genau die Formen aus, nach denen hier gesucht
     * wird. An der rohen Datei gemessen läse eine „darf nicht vorkommen"-Suche
     * die Erklärung mit und wäre grün, weil sie etwas anderes misst als
     * behauptet (derselbe Fehler wie in Fall 21).
     */
    public function testDerAbgelehnteKaufWirdAmKaufknopfGemeldet(): void
    {
        // C-3c: Der Grund kommt aus der Adresse und wird hier gezeigt, nicht
        // mehr auf der Warenkorbseite. Der Schlüssel wird aus `notice.value`
        // zusammengesetzt, steht also nirgends ausgeschrieben — dieselbe
        // Konvention wie im Warenkorb, damit beide dieselben Sätze nutzen.
        self::assertMatchesRegularExpression(
            '/\(\s*"CONSHO\.cart\.notice\."\s*~\s*notice\.value\s*\)\|trans/',
            $this->markup(),
        );
    }

    public function testDieMeldungHaengtNichtAmVorhandenenKaufknopf(): void
    {
        // Eine Variante, die noch zu wählen ist, hat keinen Kaufknopf
        // (purchase.articleId ist null) — eine Ablehnung dazu muss trotzdem
        // lesbar sein. Die Meldung steht deshalb VOR der Bedingung, nicht
        // darin. Geprüft an den Positionen im Quelltext, weil genau das die
        // Entwurfsentscheidung ist.
        $markup = $this->markup();

        $meldung = strpos($markup, '{% if notice %}');
        $knopf = strpos($markup, '{% if purchase.articleId %}');

        self::assertIsInt($meldung, 'die Meldung muss es geben');
        self::assertIsInt($knopf, 'die Kaufknopf-Bedingung muss es geben');
        self::assertLessThan($knopf, $meldung, 'die Meldung steht außerhalb und vor der Kaufknopf-Bedingung');
    }

    /**
     * Block G (G7): Der Zustand steht vor der Kaufknopf-Bedingung — bei
     * „derzeit nicht verfügbar" ist purchase.articleId null, der Satz muss
     * trotzdem da sein. Der Schlüssel wird aus dem Zustand zusammengesetzt.
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
        // Abo-2 (P10): AddToCartController liest `subscriptionRule`. Stünde die
        // Wahl außerhalb des Formulars oder unter anderem Namen, käme jeder
        // Klick als Einmalkauf an — genau der Fehler aus Abo-1, nur leiser.
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
        // for und id aus derselben Variable, die Bedingungen über
        // aria-describedby an ihrem Knopf.
        self::assertStringContainsString('id="{{ plan_id }}"', $markup);
        self::assertStringContainsString('<label for="{{ plan_id }}">', $markup);
        self::assertStringContainsString('aria-describedby="{{ plan_id }}-terms"', $markup);
        self::assertStringContainsString('id="{{ plan_id }}-terms"', $markup);
        // Je Inhaltselement eindeutig, wie das Mengenfeld.
        self::assertMatchesRegularExpression("/set plan_id = 'consho-plan-' ~ data\\.id/", $markup);
    }

    private function markup(): string
    {
        $contents = file_get_contents(self::TEMPLATE);

        self::assertIsString($contents);

        return (string) preg_replace('/\{#.*?#\}/s', '', $contents);
    }

    /**
     * Befund 5: Die übrige Datei benennt mit einer Klasse immer das Element,
     * das den Wert trägt (z. B. rich_text_component-Aufrufe). Die Klasse
     * gehört deshalb auf das Eingabefeld, nicht auf die Beschriftung.
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
     * @return array{0: string, 1: string} [for-Ausdruck, id-Ausdruck]
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
     * @return array{0: string, 1: string} [aria-describedby-Ausdruck, Hinweistext-Id-Ausdruck]
     */
    private function extractAriaDescribedbyAndHelpId(string $template): array
    {
        // Das der Menge, nicht das erste der Datei: Seit Abo-2 steht davor die
        // Modellwahl mit eigenem aria-describedby. (Das Eingabefeld selbst ist
        // per Regex nicht sauber zu fassen — `> 0` in einer Bedingung beendet
        // jedes `[^>]*`.)
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
