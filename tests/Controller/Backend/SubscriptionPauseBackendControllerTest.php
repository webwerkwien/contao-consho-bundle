<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
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
use Webwerkwien\ContaoConshoBundle\Controller\Backend\SubscriptionPauseBackendController;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseConfirmation;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseScheduled;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Pausing a subscription (P101, P107): the operator pauses in the back end,
 * also beyond the allowance, and takes back an announced pause. Real
 * pause, real template.
 */
final class SubscriptionPauseBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<PauseScheduled> */
    private array $confirmed = [];

    private string $table = 'tl_consho_subscription';

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        DcaSchema::create($this->connection, 'tl_consho_subscription_pause');

        $now = time();
        $this->connection->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00096-1', 'state' => 'active', 'billingInterval' => 1, 'pauseMonths' => 0,
            'startedAt' => $now - 10 * 86_400, 'periodFrom' => $now - 10 * 86_400, 'periodUntil' => $now + 20 * 86_400, 'nextBillingAt' => $now + 20 * 86_400,
            'minimumEndsAt' => $now + 355 * 86_400,
        ]);

        require __DIR__.'/../../../contao/languages/de/tl_consho_subscription.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDerBetreiberDarfUeberDasKontingentHinaus(): void
    {
        $html = $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1'))($this->dc());

        // The rule allows no pause (0), the operator up to a year.
        self::assertSame(12, substr_count($html, '<option value='));
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
        self::assertStringContainsString('WEB-00096-1', $html);
    }

    public function testPausierenMitBestaetigungUndZuruecknehmen(): void
    {
        try {
            $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1', 'POST', ['consho_pause_action' => 'pause', 'consho_pause_months' => '3']))($this->dc());
            self::fail('POST leitet um');
        } catch (RedirectResponseException) {
        }

        self::assertSame(3, (int) $this->connection->fetchOne('SELECT pausedMonths FROM tl_consho_subscription WHERE id = 1'));
        self::assertSame('operator', $this->connection->fetchOne('SELECT source FROM tl_consho_subscription_pause WHERE pid = 1'));
        self::assertCount(1, $this->confirmed, 'der Kunde bekommt die Bestätigung (P110)');
        self::assertSame('addConfirmation', $this->messages[0][0]);

        $html = $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1'))($this->dc());
        self::assertStringContainsString('Pause zurücknehmen', $html);

        try {
            $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1', 'POST', ['consho_pause_action' => 'withdraw']))($this->dc());
            self::fail('POST leitet um');
        } catch (RedirectResponseException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT pausedMonths FROM tl_consho_subscription WHERE id = 1'));
        self::assertSame(['addConfirmation', 'Die Pause ist zurückgenommen.'], $this->messages[1]);
    }

    public function testEineNichtAngeboteneLaengeWirdAbgelehnt(): void
    {
        try {
            $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1', 'POST', ['consho_pause_action' => 'pause', 'consho_pause_months' => '13']))($this->dc());
        } catch (RedirectResponseException) {
        }

        self::assertSame('addError', $this->messages[0][0]);
        self::assertSame([], $this->confirmed);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_subscription_pause'));
    }

    /**
     * In the child table the id is a pause.
     */
    public function testNurInDerAbotabelle(): void
    {
        $this->table = 'tl_consho_subscription_pause';

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->controller(Request::create('/contao?do=consho_subscription&table=tl_consho_subscription_pause&key=pauseSubscription&id=1'))($this->dc());
    }

    /**
     * After a cancellation the pause is still there, but without "zurücknehmen"
     * (take back).
     */
    public function testNachDerKuendigungSichtbarAberNichtZuruecknehmbar(): void
    {
        try {
            $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1', 'POST', ['consho_pause_action' => 'pause', 'consho_pause_months' => '1']))($this->dc());
        } catch (RedirectResponseException) {
        }
        $this->connection->update('tl_consho_subscription', ['endsAt' => time() + 400 * 86_400], ['id' => 1]);

        $html = $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1'))($this->dc());

        self::assertStringContainsString('Pause angekündigt', $html);
        self::assertStringNotContainsString('Pause zurücknehmen', $html);
    }

    public function testOhneRechtNichts(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?do=consho_subscription&key=pauseSubscription&id=1'), false)($this->dc());
    }

    private function controller(Request $request, bool $granted = true): SubscriptionPauseBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        // Asked for the module, not for an update of the table: that one
        // Contao denies to everybody — the table is notEditable.
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute, mixed $subject = null): bool => $granted
            && ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'consho_subscription' === $subject);

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            if ('generate' !== $name) {
                $messages[] = [$name, (string) $args[0]];
            }

            return '';
        });
        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_subscription');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $message : $system);

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);

        $lock = new class($this->connection) implements SubscriptionLockInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function lock(int $subscriptionId): array|null
            {
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);

                return false === $row ? null : $row;
            }
        };

        $confirmation = $this->createMock(PauseConfirmation::class);
        $confirmation->method('send')->willReturnCallback(function (PauseScheduled $pause): bool {
            $this->confirmed[] = $pause;

            return true;
        });

        return new SubscriptionPauseBackendController($this->connection, $security, $framework, $requests, $twig, new SubscriptionPause($this->connection, $lock, new SubscriptionCalendar()), $confirmation);
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $table = $this->table;
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => 1,
            'table' => $table,
            default => null,
        });

        return $dc;
    }
}
