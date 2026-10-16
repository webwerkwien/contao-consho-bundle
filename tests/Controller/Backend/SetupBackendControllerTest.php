<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Contao\Versions;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Webwerkwien\ContaoConshoBundle\Setup\CacheProbe;
use Webwerkwien\ContaoConshoBundle\Setup\PageCacheSetup;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Config\AcknowledgementStore;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\SetupBackendController;
use Webwerkwien\ContaoConshoBundle\EventListener\BackendSystemMessagesListener;
use Webwerkwien\ContaoConshoBundle\Setup\NumberRangeSetup;
use Webwerkwien\ContaoConshoBundle\Setup\SetupGuide;
use Webwerkwien\ContaoConshoBundle\Setup\ShopPageSetup;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Block I (I5): die Seite „Einrichtung" mit den echten deutschen Texten.
 */
final class SetupBackendControllerTest extends TestCase
{
    private Connection $connection;

    /**
     * @var list<array{string, string}>
     */
    private array $messages = [];

    private MockHttpClient|null $http = null;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['tl_page', 'tl_article', 'tl_content'] as $table) {
            DcaSchema::createCore($this->connection, $table);
        }

        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_number_range');

        $GLOBALS['TL_LANGUAGE'] = 'de';
        unset($GLOBALS['TL_LANG']);
        include __DIR__.'/../../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testOhneShopKonfigurationNurDerWegZurNeuanlage(): void
    {
        $html = $this->controller()();

        self::assertStringContainsString('Es gibt noch keine Shop-Konfiguration.', $html);
        self::assertStringContainsString('href="/contao?do=consho_shop&amp;act=create"', $html, 'I3a: über die Maske, nicht selbst angelegt');
        self::assertStringNotContainsString('consho-setup__step', $html);
    }

    public function testSchritteInReihenfolgeMitZustandUndBefund(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Shop &#40;2&#41;']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop &#40;DE&#41;', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);
        $GLOBALS['TL_LANG']['CONSHO']['findings']['mailTransportMissing'] = 'Kein Postausgang in „%shop%".';

        $html = $this->controller([new ConfigurationIssue(ConfigurationFinding::MailTransportMissing, ['shop' => 'Testshop'], 1)])();

        self::assertStringContainsString('Noch nicht bereit — dieser Shop verkauft nicht.', $html);
        self::assertMatchesRegularExpression('/Shop und Startpunkt.*Grunddaten.*Mailversand.*Versandart.*Zahlungsart.*Nummernkreise.*Shopseiten.*Rechtstexte/s', $html);
        self::assertMatchesRegularExpression('/consho-setup__step--open">.*?Mailversand.*?Kein Postausgang in „Testshop"\./s', $html);
        $first = substr($html, (int) strpos($html, 'id="shop-1"'), (int) strpos($html, 'id="shop-2"') - (int) strpos($html, 'id="shop-1"'));
        self::assertSame(7, substr_count($first, 'consho-setup__step--done'), 'alle anderen Schritte von Shop 1 erledigt');
        self::assertStringContainsString('href="/contao?do=page&amp;act=edit&amp;id=5">Startpunkt „Shop (DE)&quot; bearbeiten</a>', $html, 'Contaos kodierte Klammern als Text (Review I, L4)');
        self::assertStringContainsString('<h3 class="consho-dashboard__shop-title">Shop (2)</h3>', $html);
        self::assertStringContainsString('href="/contao?do=consho_shop&amp;table=tl_consho_number_range&amp;id=1"', $html);
        self::assertStringContainsString('id="shop-1"', $html, 'Sprungziel der Operation an der Shop-Konfiguration');
    }

    public function testOhneStartpunktIstDerErsteSchrittOffen(): void
    {
        // I7: kein Befund, und trotzdem nicht bereit.
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);

        $html = $this->controller()();

        self::assertStringContainsString('Noch nicht bereit', $html);
        self::assertMatchesRegularExpression('/consho-setup__step--open">.*?Shop und Startpunkt/s', $html);
        self::assertStringContainsString('href="/contao?do=page">Seitenstruktur</a>', $html);
    }

    public function testBereit(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);

        self::assertStringContainsString('Bereit — dieser Shop verkauft.', $this->controller()());
    }

    public function testDieGruendeNurFuerWerSieBehebenDarf(): void
    {
        // H2 wie im Dashboard: der Zustand für alle, der Satz nur mit Recht
        // auf das Modul, in dem er behoben wird.
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);
        $GLOBALS['TL_LANG']['CONSHO']['findings']['paymentMethodMissing'] = 'Keine Zahlungsart.';

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $module = null): bool => ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'consho_dashboard' === $module);

        $html = $this->controller([new ConfigurationIssue(ConfigurationFinding::PaymentMethodMissing, [], 1)], $security)();

        self::assertMatchesRegularExpression('/consho-setup__step--open">.*?Zahlungsart/s', $html);
        self::assertStringNotContainsString('Keine Zahlungsart.', $html);
        self::assertStringContainsString('Das Anlegen auf Knopfdruck steht Administratoren zur Verfügung.', $html);
    }

    public function testNummernkreiseAufKnopfdruck(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $issues = [new ConfigurationIssue(ConfigurationFinding::NumberRangeMissing, ['shop' => 'Testshop', 'typeKeys' => 'order,invoice,cancellation'], 1)];

        $html = $this->controller($issues)();
        self::assertStringContainsString('name="consho_setup_action" value="ranges"', $html);
        // Turbo folgt der Weiterleitung per fetch und verliert #shop-N (c5, 2026-10-08).
        self::assertStringContainsString('class="consho-setup__action" data-turbo="false">', $html);
        self::assertStringNotContainsString('class="consho-setup__action">', $html, 'kein Formular ohne');
        self::assertStringContainsString('Fehlende Nummernkreise anlegen', $html);

        try {
            $this->controller($issues, request: $this->post('ranges', 1))();
            self::fail('Nach dem Anlegen wird umgeleitet, sonst schickt ein Neuladen das Formular erneut.');
        } catch (RedirectResponseException $e) {
            self::assertSame('/contao?do=consho_dashboard&view=setup#shop-1', $e->getResponse()->headers->get('Location'));
        }

        self::assertSame(['order', 'invoice', 'cancellation'], $this->connection->fetchFirstColumn('SELECT type FROM tl_consho_number_range WHERE pid = 1 ORDER BY id'));
        self::assertSame([['addConfirmation', 'Nummernkreise angelegt: Bestellnummer (B-), Rechnungsnummer (RE-), Stornonummer (ST-). Die Präfixe lassen sich in der Maske ändern.']], $this->messages);
    }

    public function testKeinKnopfOhneFehlendenKreisUndKeinerFuerRedakteure(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $issues = [new ConfigurationIssue(ConfigurationFinding::NumberRangeMissing, ['typeKeys' => 'order'], 1)];

        self::assertStringNotContainsString('consho_setup_action', $this->controller()());

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        self::assertStringNotContainsString('consho_setup_action', $this->controller($issues, $security)());

        $this->expectException(AccessDeniedHttpException::class);
        $this->controller($issues, $security, $this->post('ranges', 1))();
    }

    public function testShopseitenAufKnopfdruck(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop DE', 'type' => 'root', 'language' => 'de', 'consho_shop' => 1, 'published' => 1]);

        $html = $this->controller()();
        self::assertStringContainsString('name="consho_setup_target" value="5"', $html);
        self::assertStringContainsString('>Fehlende Shopseiten anlegen<', $html, 'ein Startpunkt: ohne Namen am Knopf');

        try {
            $this->controller(request: $this->post('pages', 5))();
            self::fail('Umleitung erwartet');
        } catch (RedirectResponseException $e) {
            self::assertSame('/contao?do=consho_dashboard&view=setup#shop-1', $e->getResponse()->headers->get('Location'));
        }

        self::assertSame([['addConfirmation', 'Unter „Shop DE" angelegt: Warenkorb, Kasse, Ihre Bestellung, Versandkosten. Die Seiten sind veröffentlicht und nicht in der Navigation.']], $this->messages);
        self::assertStringNotContainsString('Fehlende Shopseiten anlegen', $this->controller()(), 'danach fehlt nichts mehr');
    }

    /**
     * Ein erreichbarer Startpunkt eines anderen Shops ist kein Fremdziel: Der
     * Shop wird aus dem Startpunkt gelesen, nicht aus dem Formular.
     */
    public function testEinUnveroeffentlichterStartpunktBekommtNichts(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_page', ['id' => 6, 'title' => 'Entwurf', 'type' => 'root', 'consho_shop' => 1, 'published' => 0]);

        $this->expectException(BadRequestHttpException::class);
        $this->controller(request: $this->post('pages', 6))();
    }

    public function testDerCacheZeigtDieEinstellungUndMisstAufWunsch(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 7, 'pid' => 5, 'title' => 'Home', 'published' => 1]);

        $html = $this->controller()();
        self::assertStringContainsString('Am Startpunkt „Shop DE&quot; ist der Seiten-Cache aus (Contao-Vorgabe)', $html);
        self::assertStringContainsString('name="consho_setup_action" value="cache"', $html);
        self::assertStringContainsString('class="consho-setup__action" data-turbo="false">', substr($html, (int) strpos($html, 'consho-setup__cache')));
        self::assertStringContainsString('href="/contao?do=consho_dashboard&amp;view=setup&amp;probe=1#shop-1"', $html);
        self::assertStringNotContainsString('consho-setup__step--open">', substr($html, (int) strpos($html, 'consho-setup__cache')), 'der Cache hat keinen Zustand');

        try {
            $this->controller(request: $this->post('cache', 5))();
            self::fail('Umleitung erwartet');
        } catch (RedirectResponseException) {
        }

        self::assertSame([['addConfirmation', 'Seiten-Cache am Startpunkt „Shop DE" eingeschaltet: 1 Stunde.']], $this->messages);

        $this->http = new MockHttpClient([new MockResponse('', ['response_headers' => ['Cache-Control: public, s-maxage=3600', 'Age: 12']])]);
        $html = $this->controller(request: Request::create('/contao', 'GET', ['probe' => '1']))();

        self::assertStringContainsString('ist der Seiten-Cache eingeschaltet: 3600 Sekunden.', $html);
        self::assertStringNotContainsString('value="cache"', $html);
        self::assertStringContainsString('>Home</a>: kommt aus einem Cache (Age 12 s)', $html);
        self::assertStringContainsString('Gemessen aus Sicht des Servers', $html);
    }

    public function testNachDemZeitbudgetWirdNichtMehrGemessen(): void
    {
        // Zweites Review I, Punkt 3: der Zweig war unerreichbar.
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 7, 'pid' => 5, 'title' => 'Home', 'published' => 1]);
        $this->http = new MockHttpClient();

        $html = $this->controller(request: Request::create('/contao', 'GET', ['probe' => '1']), probeBudget: 0.0)();

        self::assertSame(0, $this->http->getRequestsCount());
        self::assertStringContainsString('>Home</a>: Messung nicht möglich: Die Zeit für diese Messung ist aufgebraucht (15 Sekunden). Bitte erneut messen.', $html);
    }

    public function testMessenNurFuerAdministratoren(): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop']);
        $this->connection->insert('tl_page', ['id' => 5, 'title' => 'Shop DE', 'type' => 'root', 'consho_shop' => 1, 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 7, 'pid' => 5, 'title' => 'Home', 'published' => 1]);
        $this->http = new MockHttpClient();

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        $html = $this->controller([], $security, Request::create('/contao', 'GET', ['probe' => '1']))();

        self::assertSame(0, $this->http->getRequestsCount());
        self::assertStringNotContainsString('Jetzt messen', $html);
    }

    private function post(string $action, int $target): Request
    {
        return Request::create('/contao', 'POST', ['FORM_SUBMIT' => 'consho_setup', 'consho_setup_action' => $action, 'consho_setup_target' => (string) $target]);
    }

    /**
     * @param list<ConfigurationIssue> $issues
     */
    private function controller(array $issues = [], Security|null $security = null, Request|null $request = null, float $probeBudget = SetupBackendController::PROBE_BUDGET): SetupBackendController
    {
        $requests = new RequestStack();
        $requests->push($request ?? Request::create('/contao'));

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            if ('generate' !== $name) {
                $messages[] = [$name, (string) $args[0]];
            }

            return '';
        });

        // Pages for the cache measurement: every id is a page of start point 5.
        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(function (string $method, array $args): PageModel {
            $page = $this->createMock(PageModel::class);
            $page->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
                'id' => (int) $args[0],
                'rootId' => 5,
                'title' => 'Home',
                default => null,
            });

            return $page;
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => PageModel::class === $class ? $pages : $message);
        $framework->method('createInstance')->willReturn($this->createStub(Versions::class));

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://shop.example/');

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/contao?'.http_build_query($parameters));

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

        return new SetupBackendController(
            new SetupGuide($this->connection),
            new BackendSystemMessagesListener([$source], $this->createMock(AcknowledgementStore::class), security: $security),
            new NumberRangeSetup($this->connection),
            new ShopPageSetup($this->connection, $framework, $router),
            new PageCacheSetup($this->connection, $framework, $router, $urls),
            new CacheProbe($this->http ?? new MockHttpClient(), static function (): void {}),
            new Environment($loader, ['strict_variables' => true]),
            $router,
            $framework,
            $requests,
            $security,
            null,
            $probeBudget,
        );
    }
}
