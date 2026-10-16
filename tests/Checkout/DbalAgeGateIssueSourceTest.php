<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Checkout\AgeGate;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutProblem;
use Webwerkwien\ContaoConshoBundle\Checkout\DbalAgeGateIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingContext;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethod;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypeInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingQuote;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalAgeGateIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_shop');

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
    }

    public function testEinArtikelAbAchtzehnOhneJedenPruefwegWirdGemeldet(): void
    {
        $this->product(minimumAge: 18);

        $issue = $this->issues()[0];

        self::assertSame(ConfigurationFinding::AgeGateMissing, $issue->finding);
        self::assertSame('1', $issue->context['count']);
        self::assertSame('Testshop', $issue->context['shop']);
    }

    public function testEineGepruefteGruppeAmShopRaeumtDenBefundWeg(): void
    {
        $this->product(minimumAge: 18);
        $this->connection->update('tl_consho_shop', ['ageVerifiedGroups' => serialize(['3'])], ['id' => 1]);

        self::assertSame([], $this->issues());
    }

    public function testEinePruefendeVersandartRaeumtDenBefundWeg(): void
    {
        // Block E bringt solche Arten mit; hier steht eine dafür.
        $this->product(minimumAge: 18);

        self::assertSame([], $this->issues(checks: 18));
    }

    /**
     * 6, 12 und 14 sind laut C19 ein Hinweis und sperren nie — dazu gibt es
     * keinen Befund. Sonst meldete jeder Spielzeugladen etwas.
     */
    public function testEinHinweisalterErzeugtKeinenBefund(): void
    {
        $this->product(minimumAge: 14);

        self::assertSame([], $this->issues());
    }

    public function testEinUnveroeffentlichterArtikelZaehltNicht(): void
    {
        $this->product(minimumAge: 18, published: 0);

        self::assertSame([], $this->issues());
    }

    public function testEinArtikelEinesAnderenShopsZaehltNicht(): void
    {
        $this->product(minimumAge: 18, shop: 2);

        self::assertSame([], $this->issues());
    }

    /**
     * Die Schwelle im SQL und die Schwelle in AgeGate müssen dieselbe sein.
     * Sie stehen an zwei Stellen — eine Konstante in einem SQL-Literal und
     * eine Wächterzeile —, also hält dieser Test sie zusammen: 15 geht durch,
     * 16 wird abgewiesen, und beide Seiten sagen dasselbe.
     */
    public function testDieSchwelleImBefundIstDieSchwelleImCheckout(): void
    {
        $gate = new AgeGate();

        self::assertNull($gate->check(DbalAgeGateIssueSource::CHECKED_FROM - 1, [], [], null, '', 1_000));
        self::assertSame(
            CheckoutProblem::AgeNotVerifiable,
            $gate->check(DbalAgeGateIssueSource::CHECKED_FROM, [], [], null, '', 1_000),
        );

        $this->product(minimumAge: DbalAgeGateIssueSource::CHECKED_FROM - 1);
        self::assertSame([], $this->issues());

        $this->product(id: 2, minimumAge: DbalAgeGateIssueSource::CHECKED_FROM);
        self::assertCount(1, $this->issues());
    }

    /**
     * 🔴 Im Review vom 2026-09-22 gefunden. Vorher genügte irgendein
     * `ageCheck !== 0`: Eine Versandart, die ab 16 prüft, hätte den Befund
     * für 18er-Artikel abgeschaltet — und der Checkout hätte sie danach
     * trotzdem mit AgeNotVerifiable abgewiesen. Genau der stille Zustand, den
     * der Befund melden soll.
     */
    public function testEineNurBisSechzehnPruefendeVersandartDecktAchtzehnNichtAb(): void
    {
        $this->product(minimumAge: 18);

        self::assertSame(ConfigurationFinding::AgeGateMissing, $this->issues(checks: 16)[0]->finding);
    }

    public function testEineAbSechzehnPruefendeVersandartDecktSechzehnAb(): void
    {
        $this->product(minimumAge: 16);

        self::assertSame([], $this->issues(checks: 16));
    }

    public function testDerBefundTraegtDenShop(): void
    {
        // Seit 11.2 sperrt ein Befund den Shop, zu dem er gehört.
        $this->product(minimumAge: 18);

        self::assertSame(1, $this->issues()[0]->shopId);
    }

    /**
     * @return list<\Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue>
     */
    private function issues(int $checks = 0): array
    {
        $type = 0 === $checks ? new FlatRateShipping() : $this->checkingType($checks);

        $repository = new class implements ShippingMethodRepositoryInterface {
            public function forShop(int $shopId, string $currency, string $language = '', string $fallback = ''): array
            {
                return [new ShippingMethod(4, 'flat_rate', 'Paket', ShippingTaxMode::Fixed, 1, [])];
            }
        };

        return (new DbalAgeGateIssueSource(
            $this->connection,
            $repository,
            new ShippingMethodTypes(new ServiceLocator(['flat_rate' => static fn (): ShippingMethodTypeInterface => $type])),
        ))->issues();
    }

    private function checkingType(int $age): ShippingMethodTypeInterface
    {
        return new class($age) implements ShippingMethodTypeInterface {
            public function __construct(private readonly int $age)
            {
            }

            public static function getType(): string
            {
                return 'flat_rate';
            }

            public function isAvailable(ShippingContext $context, array $config): bool
            {
                return true;
            }

            public function calculate(ShippingContext $context, array $config): ShippingQuote
            {
                return new ShippingQuote('0.0000');
            }

            public function capabilities(array $config): ShippingCapabilities
            {
                return new ShippingCapabilities($this->age, false);
            }
        };
    }

    private function product(int $id = 1, int $minimumAge = 0, int $published = 1, int $shop = 1): void
    {
        $this->connection->insert('tl_consho_product', ['id' => $id, 'published' => $published, 'minimumAge' => $minimumAge]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => $id, 'shop' => $shop]);
    }
}
