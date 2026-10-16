<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlers;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryReadinessInterface;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryReadinessIssueSource;
use Webwerkwien\ContaoConshoBundle\Digital\Entitlement;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block Lizenz, D7 (Michael, 2026-10-13: „ja, A"): Ein Liefertyp eines Moduls
 * meldet, warum er nicht ausliefern kann; der Kern macht daraus seine
 * Befunde — wie laut und für wen, entscheidet er.
 */
class DeliveryReadinessTest extends TestCase
{
    private Connection $connection;

    /** @var list<string> */
    private array $typeProblems = [];

    /** @var array<int, list<string>> */
    private array $articleProblems = [];

    /** @var list<list<int>> */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');

        // 1: Lizenz; 2: Variante, erbt; 3: Lizenz, unveröffentlicht; 4: Datei.
        $c = $this->connection;
        $c->insert('tl_consho_product', ['id' => 1, 'title' => 'Plugin A', 'published' => 1, 'digitalDelivery' => 'testready']);
        $c->insert('tl_consho_product', ['id' => 2, 'pid' => 1, 'title' => 'Plugin A Agentur', 'published' => 1]);
        $c->insert('tl_consho_product', ['id' => 3, 'title' => 'Entwurf', 'published' => 0, 'digitalDelivery' => 'testready']);
        $c->insert('tl_consho_product', ['id' => 4, 'title' => 'E-Book', 'published' => 1, 'digitalDelivery' => 'file']);

        $GLOBALS['TL_LANG']['CONSHO']['deliveryReason']['testready'] = [
            'keyPairMissing' => 'Lizenz: kein Schlüsselpaar.',
            'productMissing' => 'Lizenz: keine Produkt-Kennung.',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testEinFehlerDesLiefertypsIstEinBefundNichtEinerJeArtikel(): void
    {
        $this->typeProblems = ['keyPairMissing', 'keyPairMissing', 'hostMissing'];

        $issues = $this->issues();

        self::assertSame(
            [
                ['deliveryTypeNotReady', ['count' => '2', 'reason' => 'Lizenz: kein Schlüsselpaar.']],
                // Ohne Übersetzung der nackte Code — sichtbar falsch statt leer.
                ['deliveryTypeNotReady', ['count' => '2', 'reason' => 'testready: hostMissing']],
            ],
            self::shape($issues),
            'doppelte Gründe einmal; gezählt werden veröffentlichte Artikel samt erbender Variante',
        );
    }

    public function testArtikelfehlerJeGrundMitDenTitelnUndNurFuerGefragteArtikel(): void
    {
        $this->articleProblems = [
            1 => ['productMissing'],
            2 => ['productMissing', 'productMissing'],
            3 => ['productMissing'], // unveröffentlicht, nicht gefragt
            99 => ['productMissing'], // gibt es nicht
        ];

        $issues = $this->issues();

        self::assertSame([[1, 2]], $this->asked, 'gefragt nur nach veröffentlichten Artikeln dieses Typs');
        self::assertSame(
            [['deliveryArticleNotReady', ['count' => '2', 'products' => 'Plugin A, Plugin A Agentur', 'reason' => 'Lizenz: keine Produkt-Kennung.']]],
            self::shape($issues),
        );
    }

    public function testOhneVeroeffentlichtenArtikelKeinBefund(): void
    {
        $this->connection->executeStatement('UPDATE tl_consho_product SET published = 0');
        $this->typeProblems = ['keyPairMissing'];

        self::assertSame([], $this->issues());
        self::assertSame([], $this->asked, 'ohne Artikel wird nicht einmal gefragt');
    }

    public function testEinLiefertypOhneDieSchnittstelleWirdNichtGefragt(): void
    {
        $plain = new class implements DeliveryHandlerInterface {
            public static function getType(): string
            {
                return 'file';
            }

            public function release(Entitlement $entitlement): bool
            {
                return false;
            }
        };

        $source = new DeliveryReadinessIssueSource($this->connection, new DeliveryHandlers(new ServiceLocator(['file' => static fn (): DeliveryHandlerInterface => $plain])));

        self::assertSame([], $source->issues());
    }

    public function testWarnungenOhneSperreServerfehlerNurFuerAdministratoren(): void
    {
        foreach ([ConfigurationFinding::DeliveryTypeNotReady, ConfigurationFinding::DeliveryArticleNotReady] as $finding) {
            self::assertTrue($finding->isWarning(), $finding->value);
            self::assertNotContains($finding, CheckoutReadiness::BLOCKING, $finding->value);
            self::assertNotContains($finding, SetupGuide::UNSELLABLE, $finding->value);
        }

        self::assertSame([], ConfigurationFinding::DeliveryTypeNotReady->fixedIn(), 'Schlüsselpaar und Domain behebt kein Backend-Modul');
        self::assertSame(['consho_product'], ConfigurationFinding::DeliveryArticleNotReady->fixedIn());
    }

    /**
     * @return list<ConfigurationIssue>
     */
    private function issues(): array
    {
        $test = $this;
        $handler = new class($test) implements DeliveryReadinessInterface {
            public function __construct(private readonly DeliveryReadinessTest $test)
            {
            }

            public static function getType(): string
            {
                return 'testready';
            }

            public function release(Entitlement $entitlement): bool
            {
                return false;
            }

            public function typeProblems(): array
            {
                return $this->test->typeProblems();
            }

            public function articleProblems(array $articleIds): array
            {
                return $this->test->articleProblems($articleIds);
            }
        };

        $source = new DeliveryReadinessIssueSource($this->connection, new DeliveryHandlers(new ServiceLocator(['testready' => static fn (): DeliveryHandlerInterface => $handler])));

        return $source->issues();
    }

    /**
     * @return list<string>
     *
     * @internal for the handler above
     */
    public function typeProblems(): array
    {
        return $this->typeProblems;
    }

    /**
     * @param list<int> $articleIds
     *
     * @return array<int, list<string>>
     *
     * @internal for the handler above
     */
    public function articleProblems(array $articleIds): array
    {
        $this->asked[] = $articleIds;

        return $this->articleProblems;
    }

    /**
     * @param list<ConfigurationIssue> $issues
     *
     * @return list<array{string, array<string, string>}>
     */
    private static function shape(array $issues): array
    {
        return array_map(static fn (ConfigurationIssue $issue): array => [$issue->finding->value, $issue->context], $issues);
    }
}
