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
 * Every template of our own compiles, and every name in it exists.
 *
 * 🔴 `consho_checkout.html.twig` once called `contao_csrf_token_input()`
 * three times — a function that **does not exist** in Contao 5.7. It was
 * invented; the shop's own cart template next to it does it right with
 * `{{ contao.request_token }}`. Twig aborts compiling with "Unknown
 * function", so **every** checkout page would have returned a 500.
 *
 * ⚠️ **Why 2309 tests missed it.** Until then templates were checked in two
 * ways, and neither sees such a thing:
 *
 *   - as TEXT, with regular expressions on the source (checking source code
 *     as text measures the source code, not the result);
 *   - via an ArrayLoader with a COPIED excerpt, such as the truncation chain
 *     in ProductDetailPurchaseTest — and a copy does not contain the error of
 *     the original (the test must go through the real place, not rebuild it).
 *
 * On top of that, the controller test never touches the template:
 * FragmentTemplate gets a response factory there that renders nothing, and
 * what is checked is `getData()`.
 *
 * ── What this test can do and what it cannot ──────────────────────────────
 *
 * It COMPILES, it does not render. That finds syntax errors and every
 * unknown function, filter or test name — exactly the class of errors that
 * otherwise only the first page request finds. It does NOT find whether a
 * variable exists, whether the markup makes sense or whether the output is
 * right.
 *
 * ⚠️ The names below are the statement of this test, not its accessories:
 * they are what Contao and the Twig bridge really provide. Whoever adds a
 * name here **claims that it exists** — and must be able to prove it from
 * Contao's source code, the way `contao.request_token` is proven by
 * ContaoExtension::getGlobals().
 */
class TemplatesCompileTest extends TestCase
{
    /**
     * Functions from Contao and the Symfony Twig bridge, with their origin.
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
         * ⚠️ `trans` is NOT listed here. The Twig bridge offers the function `t`
         * and the FILTER `trans` — there is no function `trans`
         * (TranslationExtension::getFunctions/getFilters). Registering it here,
         * as the first version did, opened exactly the gap the comment above
         * warns about: `{{ trans('x') }}` would have been green and a 500 on a
         * live site.
         */
        't',
    ];

    /**
     * Filters of the same origin. Twig's own (default, join, raw, trim,
     * number_format, nl2br) are there anyway.
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
             * tokenize + parse, not render: that is where Twig reports unknown
             * names, and it needs neither data nor the parent template from
             * @Contao.
             */
            $nodes = $twig->parse($twig->tokenize(new Source($this->withoutExtends($source), $name)));
        } catch (SyntaxError $error) {
            self::fail(\sprintf('%s: %s (Zeile %d)', $name, $error->getRawMessage(), $error->getTemplateLine()));
        }

        // Not assertTrue(true): a tree with nodes is the proof that something
        // was really compiled and not that an empty file went through.
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
        // A test that finds nothing would always be green.
        self::assertGreaterThan(5, iterator_count(self::templates()));
    }

    /**
     * `{% extends "@Contao/…" %}` and `{{ parent() }}` need the parent
     * template, which is not available here. Both are removed because they
     * have nothing to do with the question: an unknown function name in the
     * body still stands out.
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
