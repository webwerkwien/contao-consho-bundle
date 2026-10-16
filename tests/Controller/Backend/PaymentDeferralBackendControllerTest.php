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
use Webwerkwien\ContaoConshoBundle\Controller\Backend\PaymentDeferralBackendController;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentDeferral;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * E8 (Review S2): die Seite „Zahlungsziel verschieben" — echte Vorlage,
 * echte PaymentDeferral.
 */
final class PaymentDeferralBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    private string $timezone;

    protected function setUp(): void
    {
        $this->timezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'grandTotal' => '20.0000', 'paymentDueAt' => (new \DateTimeImmutable('2026-10-01 23:59:59'))->getTimestamp()]);
        $this->connection->insert('tl_consho_order_item', ['id' => 10, 'pid' => 1, 'quantity' => '1.0000', 'lineTotal' => '20.0000']);

        require __DIR__.'/../../../contao/languages/de/tl_consho_order.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
        date_default_timezone_set($this->timezone);
    }

    public function testDieSeiteBietetAbDemFolgetagAn(): void
    {
        $html = $this->controller(Request::create('/contao?do=consho_order&key=deferPayment&id=1'))($this->dc());

        self::assertStringContainsString('min="2026-10-02"', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
        self::assertStringContainsString('WEB-1', $html);
    }

    public function testVerschiebenUndAblehnen(): void
    {
        $this->post('2026-10-31');

        self::assertSame((new \DateTimeImmutable('2026-10-31 23:59:59'))->getTimestamp(), (int) $this->connection->fetchOne('SELECT paymentDueAt FROM tl_consho_order WHERE id = 1'));
        self::assertSame(['addConfirmation', 'Das Zahlungsziel ist verschoben.'], $this->messages[0]);

        $this->post('2026-10-15');

        self::assertSame(['addError', 'Das neue Zahlungsziel muss nach dem bisherigen liegen.'], $this->messages[1]);
    }

    public function testBezahltZeigtStattDesFormularsDenGrund(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '20']);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=deferPayment&id=1'))($this->dc());

        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('kein offenes Zahlungsziel', $html);
    }

    public function testOhneRechtNichts(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?do=consho_order&key=deferPayment&id=1'), false)($this->dc());
    }

    private function post(string $day): void
    {
        try {
            $this->controller(Request::create('/contao?do=consho_order&key=deferPayment&id=1', 'POST', ['consho_due_date' => $day]))($this->dc());
            self::fail('POST leitet um');
        } catch (RedirectResponseException) {
        }
    }

    private function controller(Request $request, bool $granted = true): PaymentDeferralBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => $granted && ContaoCorePermissions::DC_PREFIX.'tl_consho_order' === $attribute);

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
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $message : $system);

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);

        $deferral = new PaymentDeferral($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));

        return new PaymentDeferralBackendController($this->connection, $security, $framework, $requests, $twig, $deferral);
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => 1,
            'table' => 'tl_consho_order',
            default => null,
        });

        return $dc;
    }
}
