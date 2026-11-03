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
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\ProviderEventBackendController;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\PaymentClaim;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRefundingHandler;

/**
 * Spec 9: "Nachträge" at an order — real template, real RefundPayout.
 */
final class ProviderEventBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<int> */
    private array $recorded = [];

    private string|null $outstanding = null;

    private string|null $claimAnswer = null;

    /** @var list<array{int, string}> */
    private array $claimed = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_payment_attempt', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 10, 'pid' => 1, 'tokenHash' => 'h10', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment', ['id' => 5, 'pid' => 1, 'amount' => '30.0000', 'receivedAt' => 100, 'attempt' => 10, 'reference' => 'tr_1', 'method' => 'Karte']);
        $this->connection->insert('tl_consho_provider_event', ['id' => 1, 'pid' => 1, 'payment' => 5, 'kind' => 'chargeback', 'reference' => 'chb_1', 'amount' => '30.0000', 'currency' => 'EUR', 'reason' => 'MD06', 'occurredAt' => 1_800_000_000, 'state' => 'open']);
        $this->connection->insert('tl_consho_provider_event', ['id' => 2, 'pid' => 1, 'payment' => 5, 'kind' => 'refund', 'reference' => 're_x', 'amount' => '10.0000', 'currency' => 'EUR', 'occurredAt' => 1_800_000_100, 'state' => 'open']);

        require __DIR__.'/../../../contao/languages/de/tl_consho_order.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testThePageListsTheEventsWithWhatCanBeDone(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'payoutState' => 'manual', 'reference' => 'W-1']);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());

        self::assertStringContainsString('chb_1', $html);
        self::assertStringContainsString('zurückgebucht', $html);
        self::assertStringContainsString('MD06', $html);
        self::assertStringContainsString('re_x', $html);
        self::assertStringContainsString('In Consho erfassen', $html);
        self::assertStringContainsString('<option value="8">', $html);
        self::assertStringContainsString('Gutschrift oder Steuerkorrektur entsteht dabei nicht', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
    }

    public function testAStandingChargebackOffersTheClaim(): void
    {
        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());
        self::assertStringNotContainsString('name="consho_day"', $html, 'nothing open');

        $this->outstanding = '30.0000';
        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());

        self::assertStringContainsString('Zahlung einfordern', $html);
        self::assertStringContainsString('30,00', $html);
        self::assertStringContainsString('value="'.PaymentClaim::defaultDay(time()).'"', $html);
    }

    public function testClaimingAsksTheCustomer(): void
    {
        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'claim', 'consho_day' => '2027-01-20']))($this->dc()));

        self::assertSame([[1, '2027-01-20']], $this->claimed);
        self::assertSame(['addConfirmation', 'Die Zahlungsaufforderung ist versendet.'], $this->messages[0]);
    }

    public function testAClaimWhoseMailFailedSaysSo(): void
    {
        $this->claimAnswer = 'mailNotSent';

        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'claim', 'consho_day' => '2027-01-20']))($this->dc()));

        self::assertSame('addInfo', $this->messages[0][0]);
        self::assertStringContainsString('Zahlungsziel ist gesetzt', $this->messages[0][1]);
    }

    public function testRecordingBooksTheRefund(): void
    {
        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'record', 'consho_event' => '2']))($this->dc()));

        self::assertSame('recorded', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = 2'));
        self::assertSame('re_x', $this->connection->fetchOne("SELECT payoutReference FROM tl_consho_payment WHERE method = 'refund'"));
        self::assertSame([1], $this->recorded, 'the balance changed');
        self::assertSame(['addConfirmation', 'Die Erstattung ist in Consho erfasst.'], $this->messages[0]);
    }

    public function testAssigningTiesTheRow(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'payoutState' => 'manual']);

        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'assign', 'consho_event' => '2', 'consho_row' => '8']))($this->dc()));

        self::assertSame('assigned', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = 2'));
        self::assertSame('re_x', $this->connection->fetchOne('SELECT payoutReference FROM tl_consho_payment WHERE id = 8'));
    }

    public function testAnEventOfAnotherOrderIsNotFound(): void
    {
        $this->connection->insert('tl_consho_provider_event', ['id' => 3, 'pid' => 2, 'kind' => 'refund', 'reference' => 're_other', 'amount' => '1.0000', 'state' => 'open']);

        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->post(['consho_action' => 'record', 'consho_event' => '3']))($this->dc());
    }

    public function testRecordingNeedsThePermissionToCreatePayments(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller($this->post(['consho_action' => 'record', 'consho_event' => '2']), payments: false)($this->dc());
    }

    public function testThePageNeedsThePermissionOnTheOrder(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'), order: false)($this->dc());
    }

    /**
     * @param array<string, string> $data
     */
    private function post(array $data): Request
    {
        return Request::create('/contao?do=consho_order&key=followUps&id=1', 'POST', $data);
    }

    private function redirected(\Closure $call): void
    {
        try {
            $call();
            self::fail('POST redirects');
        } catch (RedirectResponseException) {
        }
    }

    private function controller(Request $request, bool $order = true, bool $payments = true): ProviderEventBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => match ($attribute) {
            ContaoCorePermissions::DC_PREFIX.'tl_consho_order' => $order,
            ContaoCorePermissions::DC_PREFIX.'tl_consho_payment' => $payments,
            default => false,
        });

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

        $methods = new class implements PaymentMethodLookupInterface {
            public function byId(int $methodId): PaymentMethod|null
            {
                return 1 === $methodId ? new PaymentMethod(1, 'card', 'Visa', []) : null;
            }
        };

        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturn(['id' => '1']);

        $recorded = $this->createMock(PaymentRecorded::class);
        $calls = &$this->recorded;
        $recorded->method('handle')->willReturnCallback(static function (int $orderId) use (&$calls): void {
            $calls[] = $orderId;
        });

        $card = new FakeRefundingHandler();
        $payout = new RefundPayout($this->connection, $orders, $methods, new PaymentHandlers(new ServiceLocator([
            'card' => static fn (): PaymentHandlerInterface => $card,
        ])));

        $claims = $this->createMock(PaymentClaim::class);
        $claims->method('outstanding')->willReturnCallback(fn (): string|null => $this->outstanding);
        $claimed = &$this->claimed;
        $claims->method('claim')->willReturnCallback(function (int $orderId, string $day) use (&$claimed): string|null {
            $claimed[] = [$orderId, $day];

            return $this->claimAnswer;
        });

        return new ProviderEventBackendController($this->connection, $security, $framework, $requests, $twig, $payout, $recorded, $claims);
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
