<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Command;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoConshoBundle\Command\CheckCommand;
use Webwerkwien\ContaoConshoBundle\Config\AcknowledgementStore;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\EventListener\BackendSystemMessagesListener;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block I (I6): for deploy scripts and monitoring — the exit code is the
 * answer, the lines are the reasoning.
 */
class CheckCommandTest extends TestCase
{
    private Connection $connection;

    /**
     * @var list<string>
     */
    private array $loaded = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Shop &#40;DE&#41;']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);

        $GLOBALS['TL_LANG']['CONSHO']['findings'] = [
            'paymentMethodMissing' => 'Keine Zahlungsart in „%shop%".',
            'shippingPriceMissing' => 'Versandpreis fehlt.',
            'duplicateGtin' => 'GTIN %gtin% doppelt.',
            'listPriceDeviation' => 'Listenpreise weichen ab.',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testOhneSperreExitNull(): void
    {
        $tester = $this->tester([new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '4006381333931'], 1)]);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Shop "Shop (DE)" (1): sells', $tester->getDisplay(), 'Contaos kodierte Klammern als Text');
        self::assertStringContainsString('[note]    GTIN 4006381333931 doppelt.', $tester->getDisplay());
        self::assertSame(['de'], $this->loaded);
    }

    public function testEineSperreExitEinsUndZuerst(): void
    {
        $tester = $this->tester([
            new ConfigurationIssue(ConfigurationFinding::ShippingPriceMissing, [], 1),
            new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, ['shop' => 'Shop <DE>'], 1),
        ]);

        self::assertSame(1, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('DOES NOT SELL', $display);
        self::assertStringContainsString('[blocks]  Keine Zahlungsart in „Shop <DE>".', $display, 'Text, nicht HTML');
        self::assertLessThan(strpos($display, '[warning] Versandpreis fehlt.'), strpos($display, '[blocks]'));
    }

    public function testOhneStartpunktVerkauftDerShopNicht(): void
    {
        // I7, as on the setup page.
        $this->connection->update('tl_page', ['published' => 0], ['id' => 5]);
        $tester = $this->tester([]);

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('[blocks]  No published start point names this shop configuration.', $tester->getDisplay());
    }

    public function testBefundeOhneShopStehenUnterAlleShops(): void
    {
        $tester = $this->tester([new ConfigurationIssue(ConfigurationFinding::ListPriceDeviation, [])]);

        self::assertSame(0, $tester->execute([]), 'eine Warnung, die nicht sperrt');
        self::assertMatchesRegularExpression('/All shops.*\[warning\] Listenpreise weichen ab\./s', $tester->getDisplay());
    }

    public function testDieQuellenLesenInDerGewaehltenSprache(): void
    {
        // Country names come from TL_LANGUAGE, which nobody sets on the console.
        /** @var \ArrayObject<int, string> $seen */
        $seen = new \ArrayObject();
        $source = new class($seen) implements ConfigurationIssueSource {
            /**
             * @param \ArrayObject<int, string> $seen
             */
            public function __construct(private readonly \ArrayObject $seen)
            {
            }

            public function issues(): array
            {
                $this->seen->append((string) ($GLOBALS['TL_LANGUAGE'] ?? ''));

                return [];
            }
        };

        $tester = new CommandTester(new CheckCommand(
            $this->framework(),
            new BackendSystemMessagesListener([$source], $this->createMock(AcknowledgementStore::class)),
            new SetupGuide($this->connection),
            static fn (): int => 1_800_000_000,
        ));

        $tester->execute([]);
        $tester->execute(['--language' => 'en']);

        self::assertSame(['de', 'en'], $seen->getArrayCopy());
        self::assertArrayNotHasKey('TL_LANGUAGE', $GLOBALS, 'danach wie vorher');
    }

    public function testNurEinShop(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Shop 2']);
        $tester = $this->tester([]);

        self::assertSame(0, $tester->execute(['--shop' => '1', '--language' => 'en']), 'Shop 2 ohne Startpunkt zählt hier nicht');
        self::assertStringNotContainsString('Shop 2', $tester->getDisplay());
        self::assertSame(['en'], $this->loaded);

        self::assertSame(1, $tester->execute(['--shop' => '9']));
        // With the shops that exist — instead of making the caller try out IDs.
        self::assertStringContainsString('There is no shop configuration 9. Shop configurations: 1 (Shop (DE)), 2 (Shop 2).', preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '');
    }

    private function framework(): ContaoFramework
    {
        $loaded = &$this->loaded;
        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturnCallback(static function (string $method, array $args) use (&$loaded): void {
            $loaded[] = (string) $args[1];
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($system);

        return $framework;
    }

    /**
     * @param list<ConfigurationIssue> $issues
     */
    private function tester(array $issues): CommandTester
    {
        $source = new class($issues) implements ConfigurationIssueSource {
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
        };

        return new CommandTester(new CheckCommand(
            $this->framework(),
            new BackendSystemMessagesListener([$source], $this->createMock(AcknowledgementStore::class)),
            new SetupGuide($this->connection),
            static fn (): int => 1_800_000_000,
        ));
    }
}
