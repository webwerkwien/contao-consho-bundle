<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\Yaml\Yaml;

/**
 * Jedes `iterable`-Konstruktorargument braucht einen Tag — sonst baut der
 * Container gar nicht erst.
 *
 * 🔴 **Am 2026-09-22 im Review gefunden**, bevor es auf einen Server kam.
 * `CheckoutReadiness` nahm `private readonly iterable $sources` ohne
 * `#[AutowireIterator]`:
 *
 *     Cannot autowire service "CheckoutReadiness": argument "$sources" is
 *     type-hinted "iterable", you should configure its value explicitly.
 *
 * `iterable` ist ein Builtin-Typ. Symfonys AutowirePass leitet daraus nichts
 * ab, und ohne Vorgabewert bleibt nur der Abbruch — **derselbe Ausgang wie bei
 * der Mehrdeutigkeit am 2026-09-21:** `cache:clear` bricht ab, der alte
 * Container bleibt stehen, die Website läuft weiter mit dem alten Code, und
 * das frisch Deployte erscheint einfach nicht.
 *
 * ⚠️ **Warum die 2309 Tests daran vorbeigingen** — dieselbe Ursache wie damals:
 * Tests bauen die Klassen direkt (`new CheckoutReadiness($quellen)`) und nie
 * den Container. Siehe [Prüfungen, die nicht prüfen was man glaubt], Regel 25:
 * „Baut der Test das Objekt selbst, oder lässt er es bauen?"
 *
 * Der Schwestertest ist AutowiringIsUnambiguousTest — der fragt, ob der
 * Container WÄHLEN kann, dieser, ob er überhaupt etwas zu wählen findet.
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
             * Ein Vorgabewert rettet den Aufbau — der Container setzt dann ein
             * leeres iterable ein. Das ist legitim, aber selten gewollt, und
             * es steht dem Autor frei; kaputt geht dabei nichts.
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
        // Ein Test, der nichts findet, wäre immer grün.
        self::assertGreaterThan(200, \count($this->constructorArguments()));
    }

    /**
     * Jeder Konstruktor-Parameter jeder eigenen Klasse.
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
