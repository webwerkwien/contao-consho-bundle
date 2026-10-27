<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\TaxReportBackendController;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssLedger;
use Webwerkwien\ContaoConshoBundle\Tax\Oss\OssReport;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Block R (R6, R7): the page "Steuermeldung" (tax report) with the real
 * German texts and the export as a link.
 */
class TaxReportBackendControllerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_document', 'tl_consho_withdrawal'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'tstamp' => 1, 'title' => 'Testshop', 'country' => 'AT', 'vatId' => 'ATU12345678']);
        $this->connection->insert('tl_consho_order', ['id' => 1, 'tstamp' => 1, 'shop' => 1, 'orderNumber' => 'WEB-00001', 'currency' => 'EUR', 'paymentSettledAt' => time()]);
        $this->connection->insert('tl_consho_order_item', [
            'pid' => 1, 'tstamp' => 1, 'lineType' => 'product', 'kind' => 'physical', 'productName' => 'Tee', 'taxCountry' => 'AT',
            'destinationCountry' => 'DE', 'taxRate' => '20.0000', 'taxAmount' => '1700.0000', 'lineTotal' => '10200.0000', 'priceSource' => 'list',
        ]);

        $GLOBALS['TL_LANGUAGE'] = 'de';
        unset($GLOBALS['TL_LANG']);
        include __DIR__.'/../../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testDieSeiteZeigtZaehlerSchalterUndExport(): void
    {
        $html = $this->controller('/contao?do=consho_dashboard&view=taxReport')();

        self::assertStringContainsString('ATU12345678', $html);
        self::assertStringContainsString('Steuer im Bestimmungsland: ausgeschaltet', $html);

        // R-5: a shop without a VAT ID is named that only once.
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'tstamp' => 1, 'title' => 'Ohne UID', 'country' => 'AT', 'vatId' => '']);
        self::assertSame(1, substr_count($this->controller('/contao?do=consho_dashboard&view=taxReport')(), 'Ohne UID'));
        self::assertStringContainsString('85 %', $html, '8.500 € von 10.000 €');
        // The space before the symbol comes from ICU, a non-breaking space depending on the locale.
        self::assertMatchesRegularExpression('/Lieferschwelle von 10\.000,00[\s\x{00A0}\x{202F}]€/u', $html);
        self::assertStringContainsString('export=summary', $html);
        self::assertStringContainsString('export=records', $html);
        // DB6: a view of the dashboard, the export stays there.
        self::assertStringContainsString('do=consho_dashboard&amp;view=taxReport&amp;business=ATU12345678', $html);
        self::assertStringContainsString('class="header_back"', $html);
    }

    public function testDerExportIstEinLinkUndLiefertCsv(): void
    {
        $year = (int) date('Y');
        $quarter = intdiv((int) date('n') - 1, 3) + 1;

        try {
            $this->controller(\sprintf('/contao?do=consho_dashboard&view=taxReport&business=ATU12345678&year=%d&quarter=%d&export=records', $year, $quarter))();
            self::fail('Der Export antwortet mit der Datei.');
        } catch (ResponseException $exception) {
            $response = $exception->getResponse();
        }

        self::assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
        self::assertStringContainsString(\sprintf('oss-atu12345678-%d-q%d-belege.csv', $year, $quarter), (string) $response->headers->get('Content-Disposition'));
        // Switch off: nothing taxed in the customer's country — only the header row.
        self::assertStringStartsWith("\u{FEFF}Bestellung;Beleg;Art;", (string) $response->getContent());
        self::assertSame(1, substr_count((string) $response->getContent(), "\n"));
    }

    public function testEinQuartalAusserhalbDerListeGibtKeinenExport(): void
    {
        $html = $this->controller('/contao?do=consho_dashboard&view=taxReport&business=ATU12345678&year=2001&quarter=1&export=summary')();

        self::assertStringContainsString('ATU12345678', $html);
    }

    /**
     * Contao only checks the dashboard right. Page and export of the single
     * records are order data.
     */
    public function testOhneBestellrechtKeineSeiteUndKeinExport(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $module): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'consho_order' !== $module);

        $this->expectException(AccessDeniedHttpException::class);

        $this->controller('/contao?do=consho_dashboard&view=taxReport&business=ATU12345678&year=2026&quarter=4&export=records', $security)();
    }

    public function testMitBestellrechtDieSeite(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);

        self::assertStringContainsString('ATU12345678', $this->controller('/contao?do=consho_dashboard&view=taxReport', $security)());
    }

    private function controller(string $uri, Security|null $security = null): TaxReportBackendController
    {
        $requests = new RequestStack();
        $requests->push(Request::create($uri));

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/contao?'.http_build_query($parameters));

        return new TaxReportBackendController(
            new OssReport($this->connection, new OssLedger($this->connection)),
            $requests,
            new Environment($loader, ['strict_variables' => true]),
            $router,
            $this->createMock(ContaoFramework::class),
            security: $security,
        );
    }
}
