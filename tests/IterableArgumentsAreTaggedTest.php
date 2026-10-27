<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Yaml\Yaml;

/**
 * Every `iterable` constructor argument needs a tag — otherwise the
 * container does not even build.
 *
 * 🔴 `CheckoutReadiness` once took `private readonly iterable $sources`
 * without `#[AutowireIterator]`:
 *
 *     Cannot autowire service "CheckoutReadiness": argument "$sources" is
 *     type-hinted "iterable", you should configure its value explicitly.
 *
 * `iterable` is a builtin type. Symfony's AutowirePass derives nothing from
 * it, and without a default value the only outcome is the abort — **the
 * same outcome as with the ambiguous autowiring (AutowiringIsUnambiguousTest):**
 * `cache:clear` aborts, the old container stays in place, the website keeps
 * running the old code, and what was just deployed simply does not appear.
 *
 * ⚠️ **Why the 2309 tests missed it** — the same cause as there: tests
 * build the classes directly (`new CheckoutReadiness($quellen)`) and never
 * the container. Does the test build the object itself, or let the
 * container build it?
 *
 * The sister test is AutowiringIsUnambiguousTest — it asks whether the
 * container can CHOOSE, this one whether it finds anything to choose from
 * at all.
 */
class IterableArgumentsAreTaggedTest extends TestCase
{
    public function testJedesIterableArgumentTraegtEinenTagOderStehtInDerYaml(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../config/services.yaml')['services'] ?? [];
        $untagged = [];

        foreach ($this->constructorArguments() as [$class, $parameter]) {
            $type = $parameter->getType();

            if (!$type instanceof \ReflectionNamedType || 'iterable' !== $type->getName()) {
                continue;
            }

            /*
             * A default value rescues the build — the container then injects an
             * empty iterable. That is legitimate but rarely intended, and it is up
             * to the author; nothing breaks.
             */
            if ($parameter->isDefaultValueAvailable()) {
                continue;
            }

            if ([] !== $parameter->getAttributes(AutowireIterator::class)
                || [] !== $parameter->getAttributes(AutowireLocator::class)
                || isset($services[$class])
            ) {
                continue;
            }

            $untagged[] = $class.'::$'.$parameter->getName();
        }

        self::assertSame(
            [],
            $untagged,
            "Diese Argumente sind `iterable` und sagen dem Container nicht, WAS hineingehört.\n"
            ."Symfony kann das aus einem Builtin-Typ nicht ableiten und BAUT DEN CONTAINER NICHT —\n"
            ."cache:clear bricht ab und die Seite läuft still mit dem alten Stand weiter.\n"
            .'Entweder #[AutowireIterator(\'tag\')] setzen oder den Dienst in services.yaml konfigurieren.',
        );
    }

    public function testDerTestFindetUeberhauptArgumente(): void
    {
        // A test that finds nothing would always be green.
        self::assertGreaterThan(200, \count($this->constructorArguments()));
    }

    /**
     * Every constructor parameter of every class of our own.
     *
     * @return list<array{class-string, \ReflectionParameter}>
     */
    private function constructorArguments(): array
    {
        $src = \dirname(__DIR__).'/src';
        $arguments = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $path = str_replace('\\', '/', (string) $file->getRealPath());
            $relative = substr($path, \strlen(str_replace('\\', '/', $src)) + 1, -4);
            $class = 'Webwerkwien\\ContaoConshoBundle\\'.str_replace('/', '\\', $relative);

            if (!class_exists($class)) {
                continue;
            }

            $constructor = (new \ReflectionClass($class))->getConstructor();

            if (null === $constructor) {
                continue;
            }

            foreach ($constructor->getParameters() as $parameter) {
                $arguments[] = [$class, $parameter];
            }
        }

        return $arguments;
    }
}
