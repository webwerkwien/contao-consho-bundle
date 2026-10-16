<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Config\AcknowledgementStore;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\DashboardBackendController;
use Webwerkwien\ContaoConshoBundle\EventListener\BackendSystemMessagesListener;
use Webwerkwien\ContaoConshoBundle\Dashboard\OpenWorkReader;
use Webwerkwien\ContaoConshoBundle\Dashboard\SalesFigures;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssLedger;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssReport;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;
use Webwerkwien\ContaoConshoBundle\Stock\StockIssueSource;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * Dashboard DB1, DB3, DB7, DB8: die Seite mit den echten deutschen Texten.
 */
final class DashboardBackendControllerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_complaint', 'tl_consho_stock', 'tl_consho_product', 'tl_consho_document'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'country' => 'AT', 'vatId' => 'ATU12345678']);
        // Block I: ohne veröffentlichten Startpunkt gilt der Shop als unvollständig.
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'title' => 'Testshop', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '10.0000', 'currency' => 'EUR', 'language' => 'de', 'paymentDueAt' => time() - 86_400, 'createdAt' => time()]);
        $this->connection->insert('tl_consho_order_item', ['pid' => 1, 'quantity' => '1.0000', 'lineType' => 'product']);
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 1, 'number' => 1, 'state' => 'received', 'receivedAt' => time()]);

        $GLOBALS['TL_LANGUAGE'] = 'de';
        unset($GLOBALS['TL_LANG']);
        include __DIR__.'/../../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testOffenePunkteMitLinksAufDieGefilterteListe(): void
    {
        $html = $this->controller()();

        self::assertStringContainsString('<h2>Consho Dashboard</h2>', $html);
        self::assertStringNotContainsString('Zu tun', $html, 'der Kopf ersetzt „Zu tun" (Michael, 2026-10-07)');
        self::assertStringContainsString('Offene Punkte', $html);
        self::assertStringContainsString('consho_shop=1"><strong class="consho-dashboard__count">1</strong><span class="consho-dashboard__label">Zahlungen</span>', $html);
        self::assertStringContainsString('davon 1 überfällig', $html);
        self::assertStringContainsString('<span class="consho-dashboard__label">Lieferungen</span>', $html, 'auf Rechnung: die Ware geht vor dem Geld');
        self::assertMatchesRegularExpression('/consho_filter=undecided[^"]*"><strong class="consho-dashboard__count">1<\/strong><span class="consho-dashboard__label">Widerrufe<\/span><\/a>\s*<span class="consho-dashboard__note">\s*bis /', $html);
        self::assertStringContainsString('do=consho_order&amp;consho_filter=unpaid&amp;consho_shop=1', $html);
        self::assertStringContainsString('consho_filter=overdue', $html);
        self::assertStringNotContainsString('Reklamationen', $html, 'D3: nur, wenn es eine gibt');
        self::assertStringNotContainsString('Testshop', $html, 'ein Shop braucht keine Überschrift (DB7)');
    }

    /**
     * D1: Die vier Karten stehen immer; eine 0 ist grau, ohne Link und ohne Frist.
     */
    public function testEineNullBleibtStehenGrauUndOhneLink(): void
    {
        $html = $this->controller()();

        self::assertMatchesRegularExpression('/<li class="consho-dashboard__tile consho-dashboard__tile--empty">\s*<strong class="consho-dashboard__count">0<\/strong><span class="consho-dashboard__label">Erstattungen<\/span>\s*<\/li>/', $html);
        self::assertStringNotContainsString('consho_filter=refundOpen', $html);
        self::assertSame(4, substr_count($html, '<li class="consho-dashboard__tile'), 'Zahlungen, Lieferungen, Widerrufe, Erstattungen');
    }

    public function testEineReklamationErscheintAlsFuenfteKarte(): void
    {
        $this->connection->insert('tl_consho_complaint', ['pid' => 1, 'receivedAt' => time()]);

        $html = $this->controller()();

        self::assertStringContainsString('<span class="consho-dashboard__label">Reklamationen</span>', $html);
        self::assertSame(5, substr_count($html, '<li class="consho-dashboard__tile'));
    }

    public function testKennzahlenUndLieferschwelleJeShop(): void
    {
        $html = $this->controller()();

        self::assertStringContainsString('Bestellungen und Bestellwert brutto, ohne Stornos', $html);
        self::assertMatchesRegularExpression('/<span class="consho-dashboard__label">Heute<\/span>\s*<strong class="consho-dashboard__count">1<\/strong>\s*<span class="consho-dashboard__total">10,00[\s\x{00A0}\x{202F}]€<\/span>/u', $html);
        self::assertMatchesRegularExpression('/Lieferschwelle<\/a> \(ATU12345678\):\s*<span class="consho-dashboard__level--ok">0 %<\/span>,\s*Vorjahr <span class="consho-dashboard__level--ok">0 %<\/span>/', $html);
        self::assertStringNotContainsString('gemeinsam mit', $html);
        self::assertStringContainsString('do=consho_dashboard&amp;view=taxReport', $html, 'DB6: die Seite ist eine Ansicht des Dashboards');
    }

    public function testShopsMitDerselbenUidNennenEinander(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zweiter Shop', 'country' => 'AT', 'vatId' => 'ATU12345678']);

        $html = $this->controller()();

        self::assertStringContainsString('gemeinsam mit Zweiter Shop', $html);
        self::assertStringContainsString('gemeinsam mit Testshop', $html);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function schwellen(): iterable
    {
        yield 'darunter' => [89, ''];
        yield 'ab 90 % orange' => [90, 'near'];
        yield 'knapp darunter' => [99, 'near'];
        yield 'ab 100 % rot und dick' => [100, 'over'];
        yield 'darüber' => [140, 'over'];
    }

    /**
     * @dataProvider schwellen
     */
    public function testStufeDerLieferschwelle(int $percent, string $level): void
    {
        self::assertSame($level, DashboardBackendController::level($percent));
    }

    public function testOhneBestellrechtKeineBestelldaten(): void
    {
        $html = $this->controller($this->without('consho_order'))();

        // Gegenstück im selben Zustand mit Recht: testOffenePunkte…, testKennzahlenUndLieferschwelleJeShop.
        self::assertStringNotContainsString('Bestellwert', $html, 'DB8');
        self::assertStringNotContainsString('Zahlungen', $html);
        self::assertStringNotContainsString('Lieferungen', $html);
        self::assertStringNotContainsString('do=consho_order', $html);
        // Steuermeldung und Export sind Bestelldaten (Review A1).
        self::assertStringNotContainsString('Lieferschwelle', $html);
        self::assertStringNotContainsString('Steuermeldung', $html);
        self::assertStringNotContainsString('view=taxReport', $html);
        self::assertStringContainsString('<span class="consho-dashboard__label">Widerrufe</span>', $html, 'das übrige Recht bleibt');
    }

    public function testLagerNenntDieArtikelZumNachbestellenNurMitLagerrecht(): void
    {
        $this->keyStock(1);

        $html = $this->controller()();

        self::assertMatchesRegularExpression('/consho_filter=low">Nachbestellen: 1 Artikel unter Meldebestand<\/a><\/h3>\s*<ul>\s*<li>Spiel 1 \(0\)<\/li>/', $html);
        self::assertStringNotContainsString('weitere', $html);
        self::assertStringNotContainsString('Meldebestand', $this->controller($this->without('consho_stock'))(), 'DB8');
    }

    public function testUeberZehnArtikelVerweistDerRestAufDieListe(): void
    {
        $this->keyStock(DashboardBackendController::STOCK_ITEMS + 2);

        $html = $this->controller()();

        self::assertSame(DashboardBackendController::STOCK_ITEMS, substr_count($html, '<li>Spiel '));
        self::assertStringContainsString('Nachbestellen: 12 Artikel unter Meldebestand', $html);
        self::assertStringContainsString('consho_filter=low">und 2 weitere</a>', $html);
    }

    /**
     * Schlüsselvorräte ohne Codes: verfügbar 0, unter dem Meldebestand.
     */
    private function keyStock(int $articles): void
    {
        foreach (['tl_consho_product_type', 'tl_consho_key', 'tl_consho_stock_movement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);

        for ($id = 1; $id <= $articles; ++$id) {
            $this->connection->insert('tl_consho_product', ['id' => $id, 'title' => 'Spiel '.$id, 'productType' => 1, 'digitalDelivery' => 'key', 'stockWarning' => '1.0000', 'published' => 1]);
            (new StockRows($this->connection))->sync($id, 100);
        }
    }

    private function without(string ...$modules): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $subject): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && !\in_array($subject, $modules, true));

        return $security;
    }

    public function testMehrereShopsBekommenUeberschriften(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zweiter Shop']);

        $html = $this->controller()();

        self::assertStringContainsString('<h3 class="consho-dashboard__shop-title">Testshop</h3>', $html);
        self::assertStringContainsString('<h3 class="consho-dashboard__shop-title">Zweiter Shop</h3>', $html);
        self::assertSame(8, substr_count($html, '<li class="consho-dashboard__tile'), 'D1: auch der Shop ohne Offenes zeigt seine vier Karten');
    }

    public function testOhneRechtAufDasModulKeineKachel(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $module): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'consho_withdrawal' !== $module);

        $html = $this->controller($security)();

        self::assertStringContainsString('<span class="consho-dashboard__label">Zahlungen</span>', $html);
        self::assertStringNotContainsString('Widerrufe', $html, 'DB8, D4');
        self::assertStringNotContainsString('Erstattungen', $html, 'DB8, D4');
        self::assertStringNotContainsString('consho_withdrawal', $html);
        self::assertSame(2, substr_count($html, '<li class="consho-dashboard__tile'));
    }

    public function testEinSperrenderBefundMachtDenShopUnvollstaendigUndHinweiseStehenHier(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zweiter Shop']);
        $GLOBALS['TL_LANG']['CONSHO']['findings'] = ['paymentMethodMissing' => 'Keine Zahlungsart.', 'duplicateGtin' => 'Doppelte GTIN.'];

        $html = $this->controller(issues: [
            new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, [], 2),
            new ConfigurationIssue(ConfigurationFinding::DuplicateGtin, ['gtin' => '4006381333931']),
        ])();

        self::assertSame(1, substr_count($html, 'Einrichtung unvollständig'), 'nur der Shop, den der Befund sperrt');
        self::assertGreaterThan(strpos($html, 'Zweiter Shop'), strpos($html, 'Einrichtung unvollständig'));
        // Oben zwei zählende Kästen, die Listen in je einem Dialog (DB1–DB3, 2026-10-09).
        self::assertMatchesRegularExpression('/<button type="button" class="consho-dashboard__finding consho-dashboard__finding--warnings" data-consho-dialog="consho-findings-warnings"[^>]*>\s*<strong class="consho-dashboard__count">1<\/strong><span class="consho-dashboard__label">Warnungen<\/span>/', $html);
        self::assertMatchesRegularExpression('/<button type="button" class="consho-dashboard__finding consho-dashboard__finding--notes" data-consho-dialog="consho-findings-notes"[^>]*>\s*<strong class="consho-dashboard__count">1<\/strong><span class="consho-dashboard__label">Hinweise<\/span>/', $html);
        self::assertLessThan(strpos($html, 'consho-dashboard__shops'), strpos($html, 'consho-dashboard__findings'), 'oben, vor den Shops');

        $warnings = substr($html, (int) strpos($html, '<dialog id="consho-findings-warnings"'));
        $notes = substr($html, (int) strpos($html, '<dialog id="consho-findings-notes"'));
        self::assertStringContainsString('Keine Zahlungsart.', strstr($warnings, '</dialog>', true) ?: '');
        self::assertStringContainsString('Doppelte GTIN.', strstr($notes, '</dialog>', true) ?: '');
        self::assertStringContainsString('name="consho_ack"', strstr($notes, '</dialog>', true) ?: '', 'ein Hinweis lässt sich im Dialog quittieren (DB2)');
    }

    /**
     * DB1: Bei 0 stehen beide Kästen grau da, ohne etwas zu öffnen — die
     * Seite springt nicht.
     */
    public function testOhneBefundeZweiGraueKaestenOhneDialog(): void
    {
        $html = $this->controller(issues: [])();

        self::assertSame(2, substr_count($html, 'consho-dashboard__finding consho-dashboard__finding--empty'));
        self::assertStringNotContainsString('<dialog', $html);
        self::assertStringNotContainsString('data-consho-dialog', $html);
    }

    /**
     * H2: Der Status bleibt für alle; wer die Gründe nicht sehen darf, liest,
     * wer sie sieht.
     */
    public function testOhneRechtAufDieGruendeSagtDieZeileWerSieSieht(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Zweiter Shop']);
        $GLOBALS['TL_LANG']['CONSHO']['findings'] = ['paymentMethodMissing' => 'Keine Zahlungsart.'];
        $issues = [new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, [], 2)];

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $module = null): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && \in_array($module, ['consho_dashboard', 'consho_order'], true));

        $html = $this->controller($security, $issues)();

        self::assertStringContainsString('Die Gründe sieht, wer die Einrichtung bearbeiten darf.', $html);
        self::assertStringNotContainsString('Keine Zahlungsart.', $html);
        self::assertStringNotContainsString('Einrichtung fortsetzen', $html);

        // Gegenprobe mit Recht auf die Zahlungsarten.
        $html = $this->controller($this->without('consho_withdrawal'), $issues)();

        self::assertStringContainsString('Einrichtung unvollständig — dieser Shop verkauft nicht. <a href="/contao?do=consho_dashboard&amp;view=setup">Einrichtung fortsetzen</a>', $html, 'Block I: die Zeile führt zur Einrichtung');
        self::assertStringContainsString('Keine Zahlungsart.', $html);
    }

    public function testOhneStartpunktIstDerShopUnvollstaendigWieAufDerEinrichtungsseite(): void
    {
        // Review I, S2: vorher sagte das Dashboard nichts, die Einrichtung
        // „Noch nicht bereit" und consho:check Exit 1.
        self::assertStringNotContainsString('Einrichtung unvollständig', $this->controller()());

        $this->connection->update('tl_page', ['published' => 0], ['id' => 1]);

        self::assertStringContainsString('Einrichtung unvollständig — dieser Shop verkauft nicht. <a href="/contao?do=consho_dashboard&amp;view=setup">', $this->controller()());
    }

    public function testEineVerborgeneWaehrungsluckeSagtWerDieGruendeSieht(): void
    {
        // Zweites Review I, Punkt 4: der Satz folgte nur den sperrenden
        // Befunden, „unvollständig" schon „Bereit".
        $GLOBALS['TL_LANG']['CONSHO']['findings'] = ['rootWithoutCurrency' => 'Startpunkt ohne Währung.'];
        $issues = [new ConfigurationIssue(ConfigurationFinding::RootWithoutCurrency, ['page' => 'Testshop', 'shop' => 'Testshop'], 1)];

        $html = $this->controller($this->without('page', 'consho_shop'), $issues)();

        self::assertStringContainsString('Die Gründe sieht, wer die Einrichtung bearbeiten darf.', $html);
        self::assertStringNotContainsString('Startpunkt ohne Währung.', $html);

        // Gegenprobe: fehlt nur der Startpunkt, gibt es keinen Befund — die
        // schlichte Zeile mit dem Weg zur Einrichtung, die es dort sagt.
        $this->connection->update('tl_page', ['published' => 0], ['id' => 1]);
        $html = $this->controller($this->without('page', 'consho_shop'))();

        self::assertStringContainsString('Einrichtung fortsetzen', $html);
        self::assertStringNotContainsString('Die Gründe sieht', $html);
    }

    public function testOhneShopKonfigurationFuehrtDasDashboardZurEinrichtung(): void
    {
        // Block I (I5): sonst zeigt eine frische Installation ein leeres Dashboard.
        $this->connection->delete('tl_consho_shop', ['id' => 1]);

        self::assertStringContainsString('Noch keine Shop-Konfiguration — Einrichtung beginnen', $this->controller()());
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        self::assertStringNotContainsString('Einrichtung beginnen', $this->controller()());
    }

    /**
     * @param list<ConfigurationIssue> $issues
     */
    private function source(array $issues): ConfigurationIssueSource
    {
        return new class($issues) implements ConfigurationIssueSource {
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
    }

    /**
     * @param list<ConfigurationIssue> $issues
     */
    private function controller(Security|null $security = null, array $issues = []): DashboardBackendController
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/contao?'.http_build_query($parameters));

        return new DashboardBackendController(
            $this->connection,
            new OpenWorkReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator())),
            new StockIssueSource($this->connection, new Availability($this->connection)),
            new Environment($loader, ['strict_variables' => true]),
            $router,
            $this->createMock(ContaoFramework::class),
            new BackendSystemMessagesListener([$this->source($issues)], $this->createMock(AcknowledgementStore::class), security: $security),
            new SalesFigures($this->connection),
            new OssReport($this->connection, new OssLedger($this->connection)),
            new SetupGuide($this->connection),
            $security,
        );
    }
}
