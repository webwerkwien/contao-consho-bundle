<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Yaml\Yaml;

/**
 * Jede Schnittstelle mit mehr als einer Implementierung braucht eine
 * Entscheidung — sonst baut der Container gar nicht erst.
 *
 * 🔴 **Am 2026-09-21 live passiert.** Eine zweite Implementierung von
 * `PriceRepositoryInterface` (`InMemoryPriceRepository`, für einen Befund, der
 * den Rechner mehrfach befragen muss) machte das Autowiring **jedes**
 * Verbrauchers mehrdeutig:
 *
 *     Cannot autowire service "ArticlePriceResolver": argument "$repository"
 *     references interface "PriceRepositoryInterface"
 *
 * `cache:clear` brach daraufhin ab — der alte Container blieb stehen, die
 * Website lief weiter mit dem **alten Code**, und der frisch deployte Befund
 * erschien einfach nicht. Kein Fehler im Frontend, kein Fehler im Backend.
 * Aufgefallen nur, weil der erwartete Hinweis fehlte und ich ins Log sah.
 *
 * ⚠️ **Warum 2185 grüne Tests daran vorbeigingen** — dieselbe Ursache wie beim
 * `%env(default::…)%`-Vorfall am selben Tag: Tests bauen die Klassen direkt
 * und nie den Container. Die Mehrdeutigkeit entsteht erst beim Verdrahten, und
 * dorthin sieht keine Unit. Siehe [Prüfungen, die nicht prüfen was man glaubt],
 * Regel 25.
 *
 * Dieser Test baut den Container nicht nach — das wäre eine zweite Abschrift
 * von Symfonys Regeln. Er prüft die **Voraussetzung**, unter der die Frage
 * überhaupt entsteht: zwei Implementierungen einer eigenen Schnittstelle, und
 * nichts, was die Wahl trifft.
 */
class AutowiringIsUnambiguousTest extends TestCase
{
    public function testJedeMehrfachImplementierteSchnittstelleIstEntschieden(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../config/services.yaml')['services'] ?? [];
        $excluded = $this->excluded($services);

        $implementations = [];

        foreach ($this->classes() as $file => $class) {
            // Ausgeschlossene Klassen sind kein Dienst und machen deshalb
            // nichts mehrdeutig.
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
             * Eine GETAGGTE Schnittstelle ist mit Absicht mehrfach
             * implementiert: Symfony sammelt sie und reicht sie als `iterable`
             * weiter, nie einzeln — ConfigurationIssueSource sagt das in
             * seinem eigenen Kommentar („Tagged, and therefore plural").
             * Mehrdeutig wird nur, was jemand als EIN Argument erwartet.
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
        // Ein Test, der nichts findet, wäre immer grün.
        self::assertGreaterThan(100, \count($this->classes()));
    }

    /**
     * @param class-string $interface
     */
    private function isTagged(string $interface): bool
    {
        return [] !== (new \ReflectionClass($interface))->getAttributes(AutoconfigureTag::class);
    }

    /**
     * Die `exclude`-Einträge als Dateipfade, Glob-Klammern aufgelöst.
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
     * Jede Klasse unter `src/`, über den Dateipfad gefunden und dann geladen.
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

            // Aufzählungen, Schnittstellen und Merkmale implementieren nichts,
            // was der Container bauen müsste.
            if (class_exists($class) && !(new \ReflectionClass($class))->isAbstract()) {
                $classes[$path] = $class;
            }
        }

        return $classes;
    }
}
