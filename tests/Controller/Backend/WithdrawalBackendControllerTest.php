<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\WithdrawalBackendController;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\DocumentRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalProcessing;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ValueCompensation;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSignals;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Die Seite „Widerruf bearbeiten" (D-3) — mit der echten Vorlage
 * (strict_variables) und dem echten Dienst auf SQLite.
 */
final class WithdrawalBackendControllerTest extends TestCase
{
    private const RECEIVED = 1_790_000_000;

    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        // From its DCA (Fall 31): a hand-written copy did not know stockBooked (Block G).
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');
        DcaSchema::create($this->connection, 'tl_consho_document');

        $this->connection->insert('tl_consho_shop', ['id' => 1]);
        // Currency and language as OrderFactory writes them; the DCA defaults
        // are empty (the hand-written table defaulted to EUR/de).
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 1, 'orderNumber' => 'WEB-00077', 'grandTotal' => '22.8000', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Kräutersalz', 'quantity' => '2.0000', 'unitPrice' => '7.4167', 'taxRate' => '20.0000', 'taxAmount' => '2.9667', 'lineTotal' => '17.8000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'productName' => 'Paket', 'quantity' => '1.0000', 'unitPrice' => '4.1667', 'taxRate' => '20.0000', 'taxAmount' => '0.8333', 'lineTotal' => '5.0000', 'lineType' => 'shipping']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 1, 'pid' => 7, 'number' => 'WEB-00077-W1', 'receivedAt' => self::RECEIVED, 'source' => 'orderPage', 'name' => 'Test <b>', 'email' => 'kundin@example.org', 'scope' => 'items', 'message' => 'passt nicht']);
        $this->connection->insert('tl_consho_withdrawal_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);

        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/de/tl_consho_withdrawal.php';
        $GLOBALS['TL_LANGUAGE'] = 'de';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testDieSeiteZeigtErklaerungFristenUndDieVorbelegteMenge(): void
    {
        $html = $this->controller(Request::create('/contao?key=processWithdrawal&id=1'))($this->dc());

        // F12: Kopf mit den drei Symbolen und dem Link zur Bestellung.
        self::assertStringContainsString('WEB-00077-W1', $html);
        self::assertStringContainsString('zur Bestellung <a href="/contao?do=consho_order&amp;act=edit&amp;id=7">WEB-00077</a>', $html);
        self::assertStringContainsString('consho-signal--undo', $html);
        self::assertStringContainsString('Ablehnen …</summary>', $html, 'Ablehnen hinter einem Aufklappfeld');
        self::assertStringNotContainsString('tl_tip', $html, 'lange Hinweise werden nicht abgeschnitten');
        self::assertStringContainsString('Test &lt;b&gt;', $html, 'Kundeneingabe maskiert');
        self::assertStringContainsString('name="accepted[1]"', $html);
        self::assertMatchesRegularExpression('#name="accepted\[1\]"[^>]*value="1"#', $html);
        self::assertStringNotContainsString('accepted[2]', $html, 'keine Versandzeile');
        self::assertStringContainsString('Erstattung fällig bis', $html);
        self::assertStringContainsString('05.10.2026', $html, 'Eingang 21.09. + 14 Tage');
        self::assertStringNotContainsString('Erstattung buchen', $html, 'erst nach dem Annehmen');
    }

    public function testAnnehmenUndDanachDieErstattung(): void
    {
        $location = $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=processWithdrawal&id=1', 'POST', ['consho_action' => 'accept', 'accepted' => [1 => '1']]))($this->dc()));

        // Zurück auf dieselbe Seite (POST, Umleitung, GET).
        self::assertSame('http://localhost/contao?id=1&key=processWithdrawal', $location);
        self::assertSame([['addConfirmation', 'Widerruf WEB-00077-W1 angenommen.']], $this->messages);

        $html = $this->controller(Request::create('/contao?key=processWithdrawal&id=1'))($this->dc());

        self::assertStringContainsString('Erstattung buchen', $html);
        self::assertStringContainsString('noch nicht als zurückerhalten erfasst', $html);
        self::assertStringContainsString('name="returned[', $html);
        self::assertStringNotContainsString('name="accepted[', $html);
    }

    /**
     * Abo-8, P80: Die Maske nennt das Abo der Bestellung, zeigt die
     * Vorschlagsrechnung des Wertersatzes und nimmt den Betrag mit der
     * Erstattung entgegen. Das Salz ist hier ein Dienstleistungs-Abo,
     * 17,80 € für 30 Tage, 7 begonnene Tage genutzt: 4,15 €.
     */
    public function testDieMaskeZeigtAboUndWertersatz(): void
    {
        $day = 86_400;
        $this->connection->update('tl_consho_order', ['digitalConsentAt' => self::RECEIVED - 10 * $day], ['id' => 7]);
        $this->connection->update('tl_consho_order_item', ['kind' => 'service', 'subscriptionRule' => 3], ['id' => 1]);
        $this->connection->update('tl_consho_withdrawal_item', ['quantity' => '2'], ['pid' => 1]);
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00077-1', 'firstOrder' => 7, 'orderItem' => 1, 'state' => 'active', 'startedAt' => self::RECEIVED - 7 * $day, 'periodFrom' => self::RECEIVED - 7 * $day, 'periodUntil' => self::RECEIVED + 23 * $day]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => 1, 'validFrom' => self::RECEIVED - 6 * $day - 3600]);
        $this->connection->insert('tl_consho_delivery', ['id' => 5, 'pid' => 7, 'shippedAt' => self::RECEIVED - 6 * $day - 3600, 'reportedAt' => self::RECEIVED - 6 * $day - 3600]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 5, 'orderItem' => 1, 'quantity' => '2']);

        self::assertStringContainsString('WEB-00077-1', $this->controller(Request::create('/contao?key=processWithdrawal&id=1'))($this->dc()));

        $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=processWithdrawal&id=1', 'POST', ['consho_action' => 'accept', 'accepted' => [1 => '2']]))($this->dc()));
        $html = $this->controller(Request::create('/contao?key=processWithdrawal&id=1'))($this->dc());

        self::assertMatchesRegularExpression('#name="compensation"[^>]*value="4.15"#', $html);
        self::assertStringContainsString('Kräutersalz: 17,80 € × 7 / 30 Tage = 4,15 €', str_replace("\u{a0}", ' ', $html));
        // DL4 (Abnahme Abo-8): eine Dienstleistung kommt nicht zurück.
        self::assertStringNotContainsString('noch nicht als zurückerhalten erfasst', $html);

        $this->messages = [];
        $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=processWithdrawal&id=1', 'POST', ['consho_action' => 'refund', 'shipping' => '0', 'compensation' => '18']))($this->dc()));
        self::assertSame([['addError', 'Der Wertersatz muss zwischen 0 und 17.80 liegen.']], $this->messages);
    }

    public function testAblehnenOhneBegruendungMeldetDenFehler(): void
    {
        $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=processWithdrawal&id=1', 'POST', ['consho_action' => 'reject', 'reason' => '']))($this->dc()));

        self::assertSame([['addError', 'Bitte geben Sie eine Begründung an — sie geht an den Kunden.']], $this->messages);
    }

    /**
     * Abnahme D-3: Unentschieden sagt das Rücksendesymbol nicht „nicht
     * erforderlich"; abgelehnt wird keine Erstattung mehr fällig.
     */
    public function testAbgelehntOhneFaelligkeitUnentschiedenOhneNichtErforderlich(): void
    {
        $html = $this->controller(Request::create('/contao?key=processWithdrawal&id=1'))($this->dc());
        self::assertStringContainsString('Rücksendung: erst nach der Entscheidung', $html);
        self::assertStringNotContainsString('Rücksendung: nicht erforderlich', $html);

        $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=processWithdrawal&id=1', 'POST', ['consho_action' => 'reject', 'reason' => 'zu spät']))($this->dc()));
        $html = $this->controller(Request::create('/contao?key=processWithdrawal&id=1'))($this->dc());

        self::assertStringContainsString('zu spät', $html);
        self::assertStringNotContainsString('Erstattung fällig bis', $html);
        // The due date, not the decision's stamp: the rejection is stamped
        // with the server clock ("abgelehnt — 05.10.2026, 04:44"), and on
        // 05.10.2026 a plain search for the date failed.
        self::assertDoesNotMatchRegularExpression('#05\.10\.2026(?!, \d\d:\d\d)#', $html);
    }

    /**
     * Block G (G9): Ein Häkchen ohne Haken wird nicht gesendet. Entschieden
     * wird deshalb nur für die Zeilen, für die das Formular eines zeigte.
     */
    public function testInsLagerNurFuerGezeigteZeilen(): void
    {
        self::assertSame([3 => true, 4 => false], WithdrawalBackendController::restock([3 => '1', 4 => '1'], [3 => '1', 9 => '1']));
        self::assertSame([], WithdrawalBackendController::restock([], [3 => '1']));
    }

    public function testEineVerschachtelteMengeWirdIgnoriert(): void
    {
        $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=processWithdrawal&id=1', 'POST', ['consho_action' => 'accept', 'accepted' => [1 => ['x' => '1']]]))($this->dc()));

        self::assertSame('addError', $this->messages[0][0]);
        self::assertSame('received', $this->connection->fetchOne('SELECT state FROM tl_consho_withdrawal'));
    }

    public function testOhneRechtAnDerBestellungKeinZugang(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?key=processWithdrawal&id=1'), false)($this->dc());
    }

    private function redirectOf(\Closure $action): string
    {
        try {
            $action();
        } catch (RedirectResponseException $e) {
            return (string) $e->getResponse()->headers->get('Location');
        }

        self::fail('Es wurde nicht zurückgeleitet.');
    }

    private function controller(Request $request, bool $granted = true): WithdrawalBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($granted);

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            if ('generate' !== $name) {
                $messages[] = [$name, (string) $args[0]];
            }

            return '';
        });

        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_order');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => Message::class === $class ? $message : $system,
        );

        $balances = new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator());

        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $row = function (int $id): array|null {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]);

            return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
        };
        $orders->method('lockOrder')->willReturnCallback($row);
        $orders->method('order')->willReturnCallback($row);

        $processing = new WithdrawalProcessing(
            $this->connection,
            $orders,
            $this->createStub(DocumentRepositoryInterface::class),
            $this->createStub(DocumentIssuerInterface::class),
            $this->createStub(DocumentArchiverInterface::class),
            new DocumentPayloadBuilder(),
            $balances,
            $this->createStub(OrderStatusAdvancer::class),
            $this->createStub(OrderPageLink::class),
            $this->createStub(OrderEventMail::class),
            new MoneyFormatter(),
        );

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $p): string => '/contao?'.http_build_query($p));

        return new WithdrawalBackendController(
            $this->connection,
            $security,
            $framework,
            $requests,
            $processing,
            new WithdrawalWindowReader($this->connection, $balances),
            new MoneyFormatter(),
            $twig,
            new WithdrawalSignals($this->connection),
            $router,
            new ValueCompensation($this->connection),
        );
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 1 : null);

        return $dc;
    }
}
