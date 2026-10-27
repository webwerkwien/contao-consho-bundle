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
        // 11.2 in one sentence. A shop that a notice stops trains its
        // operator to overlook notices — and then the warnings too.
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::DuplicateGtin)]);

        self::assertTrue($readiness->isReady(1));
    }

    public function testEineWarnungAusserhalbDerListeSperrtNicht(): void
    {
        // A drifted projection is serious and has nothing to do with
        // ordering — consho:price:rebuild fixes it while the shop keeps
        // selling.
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
     * C-9: without the information as a file every confirmation would be
     * incomplete (Art. 8(7)(a) CRD, § 7 Abs 3 FAGG) — blocked like the page.
     * The list is written out, so a test holds every new entry.
     */
    public function testDieFehlendeGewaehrleistungsDateiSperrt(): void
    {
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::WarrantyNoticeFileMissing, [], 1)]);

        self::assertSame([ConfigurationFinding::WarrantyNoticeFileMissing], $readiness->blockers(1));
    }

    /**
     * 🔴 11.2 says: warnings block the checkout of the **affected** shop.
     * Before, every warning blocked every shop — a second shop without a
     * payment method would have stopped the first from selling. Several shops
     * are exactly what C-1c and C-3b were about.
     */
    public function testEineWarnungSperrtNurIhrenEigenenShop(): void
    {
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, [], 2)]);

        self::assertFalse($readiness->isReady(2));
        self::assertTrue($readiness->isReady(1), 'Shop 1 verkauft weiter.');
    }

    public function testEinBefundOhneShopSperrtAlle(): void
    {
        // The safe reading for a source that does not say which shop it
        // means: better to stop too much than to take a faulty order.
        $readiness = $this->readiness([new ConfigurationIssue(ConfigurationFinding::CheckoutPageMissing, [], 0)]);

        self::assertFalse($readiness->isReady(1));
        self::assertFalse($readiness->isReady(2));
    }

    public function testJedeSperrendeQuelleTraegtDenEigenenTag(): void
    {
        /*
         * ⚠️ The list of sources is DERIVED, not written down.
         *
         * The first version named two class names — and a third source that
         * later delivers a blocking finding would have walked past it green
         * (the test has to go through the real place, not rebuild it). Instead
         * `src/` is searched for whoever produces a case from BLOCKING at all;
         * exactly these files must carry the tag.
         */
        $sources = $this->sourcesProducingBlockingFindings();

        self::assertNotEmpty($sources, 'Ohne gefundene Quellen prüft dieser Test nichts.');

        foreach ($sources as $class) {
            self::assertContains(CheckoutReadiness::TAG, $this->tagsOf($class), $class);

            /*
             * The backend tag does NOT come from the class, but from
             * ConfigurationIssueSource itself — this test showed that on its
             * first run. That is why the interfaces are read along: whoever
             * hangs the second tag on the class must not lose the first one
             * in doing so.
             */
            self::assertContains(
                'consho.configuration_issue_source',
                $this->tagsOf($class),
                $class.' meldet auch im Backend',
            );
        }
    }

    /**
     * Every class under `src/` that produces a blocking finding — recognised
     * by naming the case.
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
             * Sources only. ConfigurationFinding itself names every case, and
             * ConfigurationCheck does produce them, but is called exclusively
             * by a tagged source — it is not a service of the system
             * messages.
             */
            if (class_exists($class) && \in_array(ConfigurationIssueSource::class, class_implements($class) ?: [], true)) {
                $found[] = $class;
            }
        }

        sort($found);

        return $found;
    }

    /**
     * The tags of the class AND of its interfaces.
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
