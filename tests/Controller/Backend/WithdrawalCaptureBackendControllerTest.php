<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\WithdrawalCaptureBackendController;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Webwerkwien\ContaoConshoBundle\Withdrawal\DbalWithdrawalRepository;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;

/**
 * "Widerruf erfassen": the form, the summary, and nothing stored before the
 * second step — with the real template (strict_variables) and the real
 * recorder on SQLite.
 */
final class WithdrawalCaptureBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    private int $mails = 0;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        SqliteWithoutRowLocks::$locked = [];

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        WithdrawalSchema::create($this->connection);

        $this->connection->insert('tl_consho_shop', ['id' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 1, 'orderNumber' => 'WEB-00077', 'firstname' => 'Erika', 'lastname' => 'Muster', 'email' => 'kundin@example.org', 'createdAt' => strtotime('2026-11-01 09:00'), 'grandTotal' => '17.8000', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Kräutersalz', 'quantity' => '2.0000']);

        // Not preloaded: the page runs under the order's data container and
        // has to ask for the withdrawal's labels itself.
        $GLOBALS['TL_LANG'] = [];
        $GLOBALS['TL_LANGUAGE'] = 'de';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testDasFormularIstVorbelegt(): void
    {
        $html = $this->controller(Request::create('/contao?do=consho_order&key=captureWithdrawal&id=7'))($this->dc());

        self::assertStringContainsString('zur Bestellung WEB-00077', $html);
        self::assertStringContainsString('value="Erika Muster"', $html);
        self::assertStringContainsString('<option value="letter">Brief</option>', $html);
        self::assertStringNotContainsString('value="form"', $html, 'die Wege des Shops nicht zur Wahl');
        self::assertStringContainsString('name="items[1]"', $html);
        self::assertStringContainsString('Eingangsbestätigung an kundin@example.org senden', $html);
        self::assertStringNotContainsString('tl_tip', $html);
        self::assertStringContainsString('method="post" data-turbo="false"', $html, 'Turbo drops a POST answered without a redirect');
        self::assertStringContainsString('aria-label="Kräutersalz"', $html);
    }

    public function testErstDiePruefungDannGespeichertUndZurBearbeitung(): void
    {
        $post = ['receivedAt' => '2026-11-05T14:30', 'source' => 'letter', 'name' => 'Erika <b>', 'scope' => 'items', 'items' => [1 => '1'], 'part' => '', 'message' => 'Brief'];

        $html = $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', [...$post, 'consho_action' => 'review']))($this->dc());

        self::assertStringContainsString('Prüfen und erfassen', $html);
        self::assertStringContainsString('1 × Kräutersalz', $html);
        self::assertStringContainsString('Erika &lt;b&gt;', $html, 'maskiert');
        self::assertStringContainsString('wird nicht gesendet', $html);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'), 'vor dem zweiten Schritt nichts gespeichert');
        self::assertStringContainsString('<input type="hidden" name="latest" value="0">', $html);

        $location = $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', [...$post, 'latest' => '0', 'consho_action' => 'record']))($this->dc()));

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal');

        self::assertIsArray($row);
        self::assertSame('/contao?do=consho_order&table=tl_consho_withdrawal&key=processWithdrawal&id='.$row['id'], $location);
        self::assertSame(strtotime('2026-11-05 14:30'), (int) $row['receivedAt']);
        self::assertSame('letter', $row['source']);
        self::assertSame(0, $this->mails, 'ohne Haken keine Mail');
        self::assertSame([['addConfirmation', 'Widerruf WEB-00077-W1 erfasst. Eine Eingangsbestätigung wurde nicht versandt.']], $this->messages);
    }

    public function testFehlerBleibenImFormular(): void
    {
        $html = $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', [
            'consho_action' => 'record',
            'receivedAt' => date('Y-m-d\TH:i', time() + 86400),
            'source' => 'form',
            'name' => '',
            'scope' => 'items',
            'items' => [1 => '3'],
        ]))($this->dc());

        self::assertStringContainsString('Der Eingang kann nicht in der Zukunft liegen.', $html);
        self::assertStringContainsString('Bitte wählen Sie, wie der Widerruf eingegangen ist.', $html);
        self::assertStringContainsString('Bitte geben Sie den Namen an.', $html);
        self::assertStringContainsString('Bitte geben Sie mindestens eine Menge an', $html);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    public function testNichtVorDerBestellung(): void
    {
        $html = $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', ['consho_action' => 'review', 'receivedAt' => '2026-10-01T10:00', 'source' => 'email', 'name' => 'E', 'scope' => 'all']))($this->dc());

        self::assertStringContainsString('Der Eingang kann nicht vor der Bestellung liegen.', $html);
    }

    public function testNurWerDieBestellungBearbeitenDarf(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?key=captureWithdrawal&id=7'), false)($this->dc());
    }

    /**
     * A double click sends the summary twice. The first POST records; the
     * second finds a withdrawal newer than the summary and records nothing —
     * the duplicate could only be removed by a rejection the customer is told
     * about.
     */
    public function testEinZweiterKlickAufDieselbePruefungErfasstNichtsMehr(): void
    {
        $post = ['receivedAt' => '2026-11-05T14:30', 'source' => 'letter', 'name' => 'Erika', 'scope' => 'all', 'part' => '', 'message' => 'Brief', 'latest' => '0', 'consho_action' => 'record'];

        $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', $post))($this->dc()));
        $this->messages = [];

        $location = $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', $post))($this->dc()));

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
        self::assertSame('/contao?do=consho_order&table=tl_consho_withdrawal&id=7', $location);
        self::assertSame([['addError', 'Nichts erfasst: Seit der Prüfung ist zu Bestellung WEB-00077 ein Widerruf hinzugekommen. Bitte sehen Sie in der Liste nach, ob er schon erfasst ist.']], $this->messages);
    }

    /** A summary without the field (an old page) records nothing either. */
    public function testOhneStandDerPruefungWirdNichtsErfasst(): void
    {
        $post = ['receivedAt' => '2026-11-05T14:30', 'source' => 'letter', 'name' => 'Erika', 'scope' => 'all', 'consho_action' => 'record'];

        $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', $post))($this->dc()));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    /**
     * Quantities are only checked for the scope "einzelne Positionen". Typed
     * under another scope, they neither break the summary nor appear on it.
     */
    public function testMengenUnterAnderemUmfangStoerenDiePruefungNicht(): void
    {
        $html = $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', [
            'consho_action' => 'review',
            'receivedAt' => '2026-11-05T14:30',
            'source' => 'email',
            'name' => 'Erika',
            'scope' => 'all',
            'items' => [1 => '2 Stk', 99 => '1'],
        ]))($this->dc());

        self::assertStringContainsString('Prüfen und erfassen', $html);
        self::assertStringNotContainsString('× Kräutersalz', $html);
    }

    /**
     * Abo-8: a subscription only whole — with its own message naming the
     * line, before anything reaches the recorder.
     */
    public function testEinAboNurGanzMitEigenerMeldung(): void
    {
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 3, 'productName' => 'Salz & Pfeffer-Abo'], ['id' => 1]);
        $post = ['consho_action' => 'review', 'receivedAt' => '2026-11-05T14:30', 'source' => 'email', 'name' => 'Erika', 'scope' => 'items'];

        $html = $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', [...$post, 'items' => [1 => '1']]))($this->dc());

        self::assertStringContainsString('Ein Abo lässt sich nur ganz widerrufen: Salz &amp; Pfeffer-Abo.', $html);
        self::assertStringNotContainsString('Prüfen und erfassen', $html);

        $html = $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', [...$post, 'items' => [1 => '2']]))($this->dc());

        self::assertStringContainsString('Prüfen und erfassen', $html, 'die volle Menge geht');
    }

    /** D-5: an anonymised order has nobody to record for and no address. */
    public function testEineAnonymisierteBestellungNimmtKeinenWiderrufAuf(): void
    {
        $this->connection->update('tl_consho_order', ['anonymizedAt' => 1, 'email' => ''], ['id' => 7]);

        $location = $this->redirectOf(fn () => $this->controller(Request::create('/contao?key=captureWithdrawal&id=7', 'POST', ['receivedAt' => '2026-11-05T14:30', 'source' => 'letter', 'name' => 'Erika', 'scope' => 'all', 'latest' => '0', 'consho_action' => 'record']))($this->dc()));

        self::assertSame('/contao?do=consho_order&table=tl_consho_withdrawal&id=7', $location);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
        self::assertSame('addError', $this->messages[0][0]);
        self::assertStringContainsString('anonymisiert', $this->messages[0][1]);
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

    private function controller(Request $request, bool $granted = true): WithdrawalCaptureBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        // Granted only as the order's update — a read permission or another
        // table does not record.
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $subject): bool => $granted
            && ContaoCorePermissions::DC_PREFIX.'tl_consho_order' === $attribute
            && $subject instanceof UpdateAction
            && 'tl_consho_order' === $subject->getDataSource()
            && '7' === (string) $subject->getCurrentId());

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            if ('generate' !== $name) {
                $messages[] = [$name, (string) $args[0]];
            }

            return '';
        });

        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturnCallback(static function (string $name, array $args): string {
            if ('loadLanguageFile' === $name) {
                require __DIR__.'/../../../contao/languages/de/'.$args[0].'.php';
            }

            return '/contao?do=consho_order';
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => Message::class === $class ? $message : $system,
        );

        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('withdrawalReceived')->willReturnCallback(function (): bool {
            ++$this->mails;

            return true;
        });

        $windows = new WithdrawalWindowReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
        $withdrawals = new DbalWithdrawalRepository($this->connection);

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $p): string => '/contao?'.http_build_query($p));

        return new WithdrawalCaptureBackendController(
            $this->connection,
            $security,
            $framework,
            $requests,
            new WithdrawalRecorder($this->connection, $windows, $withdrawals, $mail, $this->createStub(OrderPageLink::class)),
            $withdrawals,
            $windows,
            new MoneyFormatter(),
            $twig,
            $router,
        );
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 7 : null);

        return $dc;
    }
}
