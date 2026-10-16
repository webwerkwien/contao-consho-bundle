<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;

/**
 * Reine Textprüfungen an der Twig-**Datei**, kein Rendering — aus demselben
 * Grund wie in ProductDetailPurchaseTest: für ein `@Contao`-Fragment mit
 * Blöcken, `attrs()` und domänenspezifischem `trans` gibt es in diesem Projekt
 * keinen Twig-Umgebungsaufbau.
 *
 * Was diese Tests NICHT zeigen:
 *
 * - dass die Datei fehlerfrei rendert;
 * - dass der erzeugte HTML-Baum tatsächlich gültig ist — geprüft wird, dass
 *   jedes `<form>` im Quelltext zwischen `<td>` und `</td>` steht, nicht das
 *   Ergebnis eines HTML-Parsers;
 * - dass ein Browser beim Klick auf „Übernehmen" wirklich nur die Felder
 *   **einer** Zeile schickt (das ist die Begründung der Bauform, nicht ihr
 *   Beleg);
 * - dass zwei Warenkorb-Elemente auf einer Seite im tatsächlichen DOM
 *   verschiedene Ids tragen;
 * - dass Contaos Übersetzer die Schlüssel **zur Laufzeit** auflöst — geprüft
 *   wird, dass sie in `contao/languages/{de,en}/default.php` stehen, nicht die
 *   Auflösung selbst.
 *
 * Was sie über bloße Zeichenkettensuche hinaus leisten:
 *
 * - Die `<form>`-Blöcke werden einzeln herausgeschnitten und je Block gezählt
 *   (ein `item`, höchstens eine `quantity`, ein Anfrage-Token). Genau der
 *   Fehler aus dem Planentwurf — ein Formular über die ganze Tabelle mit je
 *   einem `item` pro Zeile — fiele damit auf, weil ein einziger Block dann
 *   mehrere `name="item"` enthielte.
 * - Die Sprachdateien werden wirklich geladen, und jeder in der Vorlage
 *   stehende `CONSHO.…`-Schlüssel muss in **beiden** einen Eintrag haben; für
 *   die Notiz zusätzlich jeder der fünf `CartNotice`-Fälle.
 */
class CartTemplateTest extends TestCase
{
    private const TEMPLATE = __DIR__.'/../../../contao/templates/content_element/consho_cart.html.twig';

    /**
     * Der Fehler, den der Plan an dieser Stelle ausdrücklich markiert: Ein
     * Browser schickt **alle** Felder des Formulars, zu dem der Knopf gehört.
     * Ein Formular über die ganze Tabelle lieferte die Positions-Id jeder
     * Zeile, und der Server läse die letzte.
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
     * Dasselbe für die Menge: Die Positions-Id am Knopf allein hätte das
     * Problem nur halb gelöst — die Mengenfelder aller übrigen Zeilen führen
     * sonst weiterhin mit, und beim Server käme wieder das letzte an.
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
     * Ein `<form>` als Kind von `<table>`, `<tbody>` oder `<tr>` ist ungültiges
     * HTML; der Parser schiebt es vor die Tabelle und die Felder stehen danach
     * in gar keinem Formular. `<td>` nimmt Fließinhalt, also gehört jedes
     * Formular vollständig in eine Zelle.
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
        // Anders als die Route „in den Warenkorb" (C11): Die Warenkorbseite ist
        // ohnehin privat, das Token darin gehört dem Besucher, der sie liest.
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
        // Die Twig-Variable `request_token` ist seit Contao 5.3 veraltet und
        // meldet das bei jedem Aufruf; `contao.request_token` ist der Nachfolger.
        $template = $this->template();

        self::assertStringContainsString('{{ contao.request_token }}', $template);
        self::assertDoesNotMatchRegularExpression('/\{\{\s*request_token\s*\}\}/', $template);
    }

    /**
     * Mehrere Mengenfelder auf einer Seite, und das Element kann zweimal auf
     * derselben Seite stehen. Die Id braucht deshalb **beides**: die Id des
     * Inhaltselements (`data.id`) gegen die zweite Instanz und die Positions-Id
     * gegen die Nachbarzeile. Die Artikel-Id wäre beides nicht.
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
     * 0 entfernt die Position (Plan, Schritt 9.3). Ein `type="number"` mit
     * `min` verböte dem Browser genau die Eingabe, auf die der Server
     * reagieren soll.
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
     * `CartView::isEmpty()` ist **falsch**, wenn nur unbepreisbare Zeilen übrig
     * sind. Die Leermeldung muss deshalb an `is_empty` hängen und nicht an
     * `lines`, sonst verschwindet eine Position, die der Besucher entfernen
     * muss, um weiterzukommen.
     */
    public function testDieLeermeldungHaengtAnIsEmptyNichtAnDenBepreistenZeilen(): void
    {
        $template = $this->markup();

        self::assertMatchesRegularExpression('/\{%\s*if\s+is_empty\s*%\}/', $template);
        self::assertDoesNotMatchRegularExpression('/\{%\s*if\s+lines\s+is\s+empty\s*%\}/', $template);
    }

    /**
     * Eine Zeile ohne Preis muss mit Titel dastehen und sich entfernen lassen —
     * ohne Titel weiß der Besucher nicht, welchen Knopf er drücken soll.
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
     * Ein Titel, der leer bleibt (das Produkt ist gelöscht), darf keine leere
     * Zelle hinterlassen — dann stünde ein „Entfernen" ohne Bezug da.
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
     * Der Befund aus der Durchsicht: Der Titel allein benennt keine Zeile.
     * CartTitles liefert absichtlich den Titel des Hauptprodukts, zwei
     * Varianten heißen dort also gleich — und zwei Zeilen ganz ohne Titel
     * fallen beide auf denselben Ersatztext. Jede Zeile braucht deshalb ein
     * eigenes Merkmal: die Artikelnummer, sonst die Position im Warenkorb.
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
     * Das Merkmal steht sichtbar in der Zeile, nicht nur im zugänglichen
     * Namen: Zwei gleich benannte Zeilen sind auch mit den Augen nicht
     * auseinanderzuhalten, und die Produktseite zeigt die Artikelnummer
     * ebenfalls offen (`consho_product_detail.html.twig`, `<dl>`).
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
        // Abo-2 (A10): sichtbar in der Zeile, bei den bepreisten Zeilen.
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
        // P12: vor dem Klick, nicht danach.
        $markup = $this->markup();

        $hint = strpos($markup, '{% if account_required|default(false) %}');
        $checkout = strpos($markup, '{% if checkout_url|default(\'\') %}');

        self::assertIsInt($hint);
        self::assertIsInt($checkout);
        self::assertLessThan($checkout, $hint);
    }

    /**
     * Beschriftung und Knöpfe werden außerhalb ihrer Zeile vorgelesen —
     * „Entfernen Wanderstock" zweimal ist für einen Screenreader derselbe
     * Name. Die Feld-Id ist zwar je Zeile eindeutig, eine Id ist aber kein
     * Name.
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

        // Der nackte Titel darf in keinem zugänglichen Namen mehr stehen —
        // sonst bliebe genau die Stelle zurück, um die es geht.
        self::assertDoesNotMatchRegularExpression('/<span class="invisible"> \{\{ line_title \}\}<\/span>/', $template);
        self::assertDoesNotMatchRegularExpression('/"CONSHO\.cart\.quantityOf"\|trans\(\[line_title\]/', $template);
    }

    /**
     * Beträge kommen fertig formatiert aus MoneyFormatter. Eine Währung, die
     * die Vorlage selbst anhängt, stünde bei jeder Sprache an derselben Stelle
     * — im Englischen gehört sie davor.
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
     * Vier Fälle, seit `other_shop` mit C-3b weggefallen ist. Der Schlüssel wird in der
     * Vorlage aus `notice.value` zusammengesetzt, steht also nirgends
     * ausgeschrieben — ein fehlender Fall fiele sonst erst dem Besucher auf,
     * dem der rohe Schlüssel angezeigt wird.
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
     * Die Sprachdatei in ein Array, ohne ein laufendes $GLOBALS['TL_LANG']
     * anderer Tests zu beschädigen.
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
     * Wie Contaos MessageCatalogue: den Punkt-Schlüssel Stufe für Stufe
     * hinuntergehen. Ein Zwischenknoten (ein Array) ist keine Beschriftung.
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
     * Die Vorlage **ohne** ihre Twig-Kommentare.
     *
     * ⚠️ Das ist keine Kosmetik. Der Kommentarblock am Kopf erklärt die
     * Bauform und schreibt dabei `<form>` und `<td>` aus. An der rohen Datei
     * gemessen begann das erste `<form …>…</form>` deshalb **im Kommentar** und
     * lief bis zum ersten echten `</form>` — die Zählung je Formular betraf
     * einen Bereich, den es im Markup gar nicht gibt. Die Tests waren grün und
     * maßen etwas anderes als behauptet; aufgefallen ist es erst, als eine
     * Mutation (ein `<form>` als Kind von `<tr>`) sie nicht rot bekam.
     *
     * Bewusst offen: Stünde `#}` in einer Zeichenkette **außerhalb** eines
     * Kommentars, schnitte der Ausdruck an der falschen Stelle. In dieser Datei
     * kommt das nicht vor, und ein Twig-Parser nur dafür wäre mehr Maschinerie
     * als Nutzen.
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
     * @return array{0: string, 1: string} [for-Ausdruck, id-Ausdruck]
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
