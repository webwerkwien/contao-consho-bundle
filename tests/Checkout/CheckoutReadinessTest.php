<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

class CheckoutReadinessTest extends TestCase
{
    public function testEinHinweisSperrtNie(): void
    {
        // 11.2 in einem Satz. Ein Shop, den eine Notiz anhält, erzieht seinen
        // Betreiber dazu, Notizen zu übersehen — und dann auch die Warnungen.
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::DuplicateGtin)]);

        self::assertTrue($readiness->isReady(1));
    }

    public function testEineWarnungAusserhalbDerListeSperrtNicht(): void
    {
        // Eine abgedriftete Projektion ist ernst und hat mit dem Bestellen
        // nichts zu tun — consho:price:rebuild richtet sie, während der Shop
        // weiterverkauft.
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::ListPriceDeviation)]);

        self::assertTrue($readiness->isReady(1));
    }

    public function testEineSperrendeWarnungSperrt(): void
    {
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, [], 1)]);

        self::assertFalse($readiness->isReady(1));
        self::assertSame([ConfigurationFinding::PaymentMethodMissing], $readiness->blockers(1));
    }

    /**
     * C-9: Ohne die Mitteilung als Datei wäre jede Bestätigung unvollständig
     * (Art. 8 Abs. 7 lit. a VRRL, § 7 Abs 3 FAGG) — gesperrt wie die Seite.
     * Die Liste ist ausgeschrieben, also hält ein Test jeden neuen Eintrag.
     */
    public function testDieFehlendeGewaehrleistungsDateiSperrt(): void
    {
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::WarrantyNoticeFileMissing, [], 1)]);

        self::assertSame([ConfigurationFinding::WarrantyNoticeFileMissing], $readiness->blockers(1));
    }

    /**
     * 🔴 11.2 im Wortlaut: „Warnungen sperren den Checkout des **betroffenen**
     * Shops." Vor dem Review vom 2026-09-22 sperrte jede Warnung jeden Shop —
     * ein zweiter Shop ohne Zahlungsart hätte dem ersten das Verkaufen
     * verboten. Mehrere Shops sind genau das, worum es in C-1c und C-3b ging.
     */
    public function testEineWarnungSperrtNurIhrenEigenenShop(): void
    {
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, [], 2)]);

        self::assertFalse($readiness->isReady(2));
        self::assertTrue($readiness->isReady(1), 'Shop 1 verkauft weiter.');
    }

    public function testEinBefundOhneShopSperrtAlle(): void
    {
        // Die sichere Lesart für eine Quelle, die nicht sagt, welchen Shop sie
        // meint: lieber zu viel anhalten als eine fehlerhafte Bestellung.
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::CheckoutPageMissing, [], 0)]);

        self::assertFalse($readiness->isReady(1));
        self::assertFalse($readiness->isReady(2));
    }

    public function testJedeSperrendeQuelleTraegtDenEigenenTag(): void
    {
        /*
         * ⚠️ Die Liste der Quellen wird ABGELEITET, nicht hingeschrieben.
         *
         * Die erste Fassung nannte zwei Klassennamen — und eine dritte Quelle,
         * die später einen sperrenden Befund liefert, wäre daran grün
         * vorbeigelaufen (Regel 24, im zweiten Review vom 2026-09-22
         * benannt). Stattdessen wird `src/` danach durchsucht, wer einen Fall
         * aus BLOCKING überhaupt erzeugt; genau diese Dateien müssen den Tag
         * tragen.
         */
        $sources = $this->sourcesProducingBlockingFindings();

        self::assertNotEmpty($sources, 'Ohne gefundene Quellen prüft dieser Test nichts.');

        foreach ($sources as $class) {
            self::assertContains(CheckoutReadiness::TAG, $this->tagsOf($class), $class);

            /*
             * Der Backend-Tag kommt NICHT von der Klasse, sondern von
             * ConfigurationIssueSource selbst — das hat dieser Test mir beim
             * ersten Lauf gesagt. Deshalb wird über die Schnittstellen
             * mitgelesen: Wer den zweiten Tag an die Klasse hängt, darf den
             * ersten nicht dabei verlieren.
             */
            self::assertContains(
                'consho.configuration_issue_source',
                $this->tagsOf($class),
                $class.' meldet auch im Backend',
            );
        }
    }

    /**
     * Jede Klasse unter `src/`, die einen sperrenden Befund erzeugt — daran
     * erkannt, dass sie den Fall beim Namen nennt.
     *
     * @return list<class-string>
     */
    private function sourcesProducingBlockingFindings(): array
    {
        /** @var list<ConfigurationFinding> $blocking */
        $blocking = (new \ReflectionClassConstant(CheckoutReadiness::class, 'BLOCKING'))->getValue();
        $needles = array_map(
            static fn (ConfigurationFinding $finding): string => 'ConfigurationFinding::'.$finding->name,
            $blocking,
        );

        $src = \dirname(__DIR__, 2).'/src';
        $found = [];

        /** @var iterable<\SplFileInfo> $files */
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src));

        foreach ($files as $file) {
            if ('php' !== $file->getExtension()) {
                continue;
            }

            $source = (string) file_get_contents((string) $file->getRealPath());
            $hit = false;

            foreach ($needles as $needle) {
                if (str_contains($source, $needle)) {
                    $hit = true;

                    break;
                }
            }

            if (!$hit) {
                continue;
            }

            $path = str_replace('\\', '/', (string) $file->getRealPath());
            $relative = substr($path, \strlen(str_replace('\\', '/', $src)) + 1, -4);
            $class = 'Webwerkwien\\ContaoConshoBundle\\'.str_replace('/', '\\', $relative);

            /*
             * Nur Quellen. ConfigurationFinding selbst nennt jeden Fall, und
             * ConfigurationCheck erzeugt sie zwar, wird aber ausschliesslich
             * von einer getaggten Quelle gerufen — es ist kein Dienst an den
             * Systemmeldungen.
             */
            if (class_exists($class) && \in_array(ConfigurationIssueSource::class, class_implements($class) ?: [], true)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Die Tags der Klasse UND ihrer Schnittstellen.
     *
     * @param class-string $class
     *
     * @return list<string>
     */
    private function tagsOf(string $class): array
    {
        $tags = [];

        foreach ([$class, ...array_values(class_implements($class) ?: [])] as $name) {
            foreach ((new \ReflectionClass($name))->getAttributes(AutoconfigureTag::class) as $attribute) {
                $tags[] = (string) ($attribute->getArguments()[0] ?? '');
            }
        }

        return $tags;
    }

    /**
     * @param list<ConfigurationIssue> $issues
     */
    private function readiness(array $issues): CheckoutReadiness
    {
        return new CheckoutReadiness([new class($issues) implements ConfigurationIssueSource {
            /**
             * @param list<ConfigurationIssue> $issues
             */
            public function __construct(private readonly array $issues)
            {
            }

            public function issues(): array
            {
                return $this->issues;
            }
        }]);
    }
}
