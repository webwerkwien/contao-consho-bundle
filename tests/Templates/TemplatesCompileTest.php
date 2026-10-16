<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Templates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\SyntaxError;
use Twig\Loader\ArrayLoader;
use Twig\Source;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Jede eigene Vorlage lässt sich übersetzen, und jeder Name darin existiert.
 *
 * 🔴 **Am 2026-09-22 im Review gefunden.** `consho_checkout.html.twig` rief
 * dreimal `contao_csrf_token_input()` auf — eine Funktion, die es in Contao
 * 5.7 **nicht gibt**. Ich hatte sie erfunden; das eigene Warenkorb-Template
 * daneben macht es richtig mit `{{ contao.request_token }}`. Twig bricht beim
 * Übersetzen mit „Unknown function" ab, also hätte **jede** Checkout-Seite
 * einen 500er geliefert.
 *
 * ⚠️ **Warum 2309 Tests daran vorbeigingen.** Vorlagen wurden bis dahin auf
 * zwei Arten geprüft, und beide sehen so etwas nicht:
 *
 *   - als TEXT, mit regulären Ausdrücken auf die Quelle (Regel 22: „Quelltext
 *     als Text zu prüfen misst den Quelltext, nicht das Ergebnis");
 *   - über einen ArrayLoader mit einem ABGESCHRIEBENEN Ausschnitt, etwa der
 *     Kürzungskette in ProductDetailPurchaseTest — und eine Abschrift enthält
 *     den Fehler des Originals nicht (Regel 24).
 *
 * Dazu kam, dass der Controller-Test die Vorlage nie anfasst: FragmentTemplate
 * bekommt dort eine Antwortfabrik, die nichts rendert, und geprüft wird
 * `getData()`.
 *
 * ── Was dieser Test kann und was nicht ────────────────────────────────────
 *
 * Er ÜBERSETZT, er rendert nicht. Das findet Syntaxfehler und jeden
 * unbekannten Funktions-, Filter- oder Test-Namen — genau die Klasse Fehler,
 * die sonst erst der erste Seitenaufruf findet. Es findet NICHT, ob eine
 * Variable existiert, ob das Markup sinnvoll ist oder ob die Ausgabe stimmt.
 *
 * ⚠️ Die Namen unten sind die Aussage dieses Tests, nicht sein Zubehör: Sie
 * sind das, was Contao und die Twig-Bridge wirklich mitbringen. Wer hier einen
 * Namen ergänzt, **behauptet, dass es ihn gibt** — und muss das an Contaos
 * Quelltext belegen können, so wie `contao.request_token` an
 * ContaoExtension::getGlobals() belegt ist.
 */
class TemplatesCompileTest extends TestCase
{
    /**
     * Funktionen aus Contao und der Symfony-Twig-Bridge, mit ihrer Herkunft.
     */
    private const FUNCTIONS = [
        // Contao\CoreBundle\Twig\Extension\ContaoExtension::getFunctions()
        'attrs', 'figure', 'contao_figure',
        // Same place, "Backend functions" (ContaoExtension.php:271, Contao
        // 5.7): BackendHelperRuntime::icon(), with the dark variant.
        'backend_icon',
        // Symfony\Bridge\Twig\Extension\RoutingExtension
        'path',
        /*
         * ⚠️ `trans` steht hier NICHT. Die Twig-Bridge bietet die Funktion `t`
         * und den FILTER `trans` — eine Funktion `trans` gibt es nicht
         * (TranslationExtension::getFunctions/getFilters). Sie hier zu
         * registrieren, wie es die erste Fassung tat, machte genau die Lücke
         * auf, vor der der Kommentar oben warnt: `{{ trans('x') }}` wäre grün
         * gewesen und live ein 500er. Im zweiten Review vom 2026-09-22
         * gefunden.
         */
        't',
    ];

    /**
     * Filter derselben Herkunft. Twigs eigene (default, join, raw, trim,
     * number_format, nl2br) sind ohnehin da.
     */
    private const FILTERS = [
        'trans',
    ];

    #[DataProvider('templates')]
    public function testEineVorlageLaesstSichUebersetzen(string $name, string $path): void
    {
        $source = file_get_contents($path);

        self::assertIsString($source, $path);

        $twig = $this->environment();
        $nodes = null;

        try {
            /*
             * tokenize + parse, nicht render: Das ist die Stelle, an der Twig
             * unbekannte Namen meldet, und sie braucht weder Daten noch die
             * Elternvorlage aus @Contao.
             */
            $nodes = $twig->parse($twig->tokenize(new Source($this->withoutExtends($source), $name)));
        } catch (SyntaxError $error) {
            self::fail(\sprintf('%s: %s (Zeile %d)', $name, $error->getRawMessage(), $error->getTemplateLine()));
        }

        // Nicht assertTrue(true): ein Baum mit Knoten ist der Beleg, dass
        // wirklich etwas übersetzt wurde und nicht eine leere Datei durchlief.
        self::assertGreaterThan(0, iterator_count($nodes->getIterator()), $name);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templates(): iterable
    {
        $root = \dirname(__DIR__, 2).'/contao/templates';

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!str_ends_with($file->getFilename(), '.html.twig')) {
                continue;
            }

            yield $file->getFilename() => [$file->getFilename(), (string) $file->getRealPath()];
        }
    }

    public function testDerTestFindetUeberhauptVorlagen(): void
    {
        // Ein Test, der nichts findet, wäre immer grün.
        self::assertGreaterThan(5, iterator_count(self::templates()));
    }

    /**
     * `{% extends "@Contao/…" %}` und `{{ parent() }}` brauchen die
     * Elternvorlage, die hier nicht vorliegt. Beide werden entfernt, weil sie
     * nichts mit der Frage zu tun haben: Ein unbekannter Funktionsname im
     * Rumpf fällt trotzdem auf.
     */
    private function withoutExtends(string $source): string
    {
        $source = (string) preg_replace('/\{%\s*extends\s+[^%]+%\}/', '', $source);

        return str_replace('{{ parent() }}', '', $source);
    }

    private function environment(): Environment
    {
        $twig = new Environment(new ArrayLoader());

        foreach (self::FUNCTIONS as $name) {
            $twig->addFunction(new TwigFunction($name, static fn (): string => ''));
        }

        foreach (self::FILTERS as $name) {
            $twig->addFilter(new TwigFilter($name, static fn (mixed $value): mixed => $value));
        }

        return $twig;
    }
}
