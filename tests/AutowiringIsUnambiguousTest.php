<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Yaml\Yaml;

/**
 * Every interface with more than one implementation needs a decision —
 * otherwise the container does not even build.
 *
 * 🔴 **This happened on a live site.** A second implementation of
 * `PriceRepositoryInterface` (`InMemoryPriceRepository`, for a case that has
 * to ask the calculator several times) made the autowiring of **every**
 * consumer ambiguous:
 *
 *     Cannot autowire service "ArticlePriceResolver": argument "$repository"
 *     references interface "PriceRepositoryInterface"
 *
 * `cache:clear` then aborted — the old container stayed in place, the
 * website kept running the **old code**, and the freshly deployed change
 * simply did not appear. No error in the front end, no error in the back
 * end. It only showed because the expected notice was missing and the log
 * said why.
 *
 * ⚠️ **Why 2185 green tests missed it** — the same cause as the
 * `%env(default::…)%` incident: tests build the classes directly and never
 * the container. The ambiguity only arises during wiring, and no unit test
 * looks there. Check the instance the runtime uses, not one the test built
 * itself.
 *
 * This test does not rebuild the container — that would be a second copy
 * of Symfony's rules. It checks the **precondition** under which the
 * question arises at all: two implementations of one of our own interfaces,
 * and nothing that makes the choice.
 */
class AutowiringIsUnambiguousTest extends TestCase
{
    public function testJedeMehrfachImplementierteSchnittstelleIstEntschieden(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../config/services.yaml')['services'] ?? [];
        $excluded = $this->excluded($services);

        $implementations = [];

        foreach ($this->classes() as $file => $class) {
            // Excluded classes are not services and therefore make nothing
            // ambiguous.
            if (\in_array($file, $excluded, true)) {
                continue;
            }

            foreach (class_implements($class) ?: [] as $interface) {
                if (str_starts_with($interface, 'Webwerkwien\\')) {
                    $implementations[$interface][] = $class;
                }
            }
        }

        $undecided = [];

        foreach ($implementations as $interface => $classes) {
            /*
             * A TAGGED interface is implemented several times on purpose: Symfony
             * collects the implementations and passes them on as an `iterable`,
             * never one by one — ConfigurationIssueSource says so in its own comment
             * ("Tagged, and therefore plural"). Only what someone expects as ONE
             * argument becomes ambiguous.
             */
            if ($this->isTagged($interface) || \count($classes) < 2 || isset($services[$interface])) {
                continue;
            }

            $undecided[$interface] = $classes;
        }

        self::assertSame(
            [],
            $undecided,
            "Diese Schnittstellen haben mehrere Implementierungen und keinen Alias in services.yaml.\n"
            ."Symfony kann dann nicht wählen und BAUT DEN CONTAINER NICHT — cache:clear bricht ab und\n"
            ."die Seite läuft still mit dem alten Stand weiter.\n"
            .'Entweder einen Alias setzen oder die Klasse unter `exclude` nehmen, wenn sie per `new` entsteht.',
        );
    }

    public function testDerTestFindetUeberhauptKlassen(): void
    {
        // A test that finds nothing would always be green.
        self::assertGreaterThan(100, \count($this->classes()));
    }

    /**
     * @param class-string $interface
     */
    private function isTagged(string $interface): bool
    {
        // A sub-interface of a tagged one is just as plural
        // (SetupIssueSource, N1): its classes come via the tag of the parent
        // interface, never one by one.
        foreach ([$interface, ...(class_implements($interface) ?: [])] as $candidate) {
            if ([] !== (new \ReflectionClass($candidate))->getAttributes(AutoconfigureTag::class)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The `exclude` entries as file paths, glob braces expanded.
     *
     * @param array<string, mixed> $services
     *
     * @return list<string>
     */
    private function excluded(array $services): array
    {
        $patterns = (array) ($services['Webwerkwien\\ContaoConshoBundle\\']['exclude'] ?? []);
        $root = \dirname(__DIR__).'/config/';

        $files = [];

        foreach ($patterns as $pattern) {
            foreach (glob($root.(string) $pattern, GLOB_BRACE) ?: [] as $match) {
                $real = realpath($match);

                if (false !== $real) {
                    $files[] = str_replace('\\', '/', $real);
                }
            }
        }

        return $files;
    }

    /**
     * Every class under `src/`, found via its file path and then loaded.
     *
     * @return array<string, class-string>
     */
    private function classes(): array
    {
        $src = \dirname(__DIR__).'/src';
        $classes = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $path = str_replace('\\', '/', (string) $file->getRealPath());
            $relative = substr($path, \strlen(str_replace('\\', '/', $src)) + 1, -4);
            $class = 'Webwerkwien\\ContaoConshoBundle\\'.str_replace('/', '\\', $relative);

            // Enums, interfaces and traits implement nothing the container would
            // have to build.
            if (class_exists($class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[$path] = $class;
            }
        }

        return $classes;
    }
}
