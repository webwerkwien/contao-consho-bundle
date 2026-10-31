<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
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
use Twig\Loader\ArrayLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\VoucherBackendController;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\VoucherIssueButtonListener;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Shop\ShopCurrencies;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Voucher\VoucherLedger;

/**
 * WG13: what the back end accepts as an amount and a date, and who may
 * create value: issuing a voucher and raising a balance are for
 * administrators, lowering a balance for anyone with the module.
 */
class VoucherBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        SqliteWithoutRowLocks::$locked = [];
        DcaSchema::create($this->connection, 'tl_consho_voucher');
        DcaSchema::create($this->connection, 'tl_consho_voucher_booking');
    }

    public function testAusstellenNurAlsAdministrator(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?do=consho_voucher&key=issueVoucher'), admin: false)->issue($this->dc(0));
    }

    public function testOhneModulrechtAuchKeinAdministratorweg(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?do=consho_voucher&key=issueVoucher'), admin: true, module: false)->issue($this->dc(0));
    }

    public function testDenSaldoErhoehenNurAlsAdministrator(): void
    {
        $id = (new VoucherLedger($this->connection))->issue(1, 'EUR', '50', '0', null, 'manual', 1_800_000_000);

        $this->correct($id, '10', admin: false);
        self::assertSame('50', $this->balance($id));
        self::assertSame('addError', $this->messages[0][0]);
        self::assertStringEndsWith('lower it with a negative amount.', $this->messages[0][1]);

        $this->correct($id, '-10', admin: false);
        self::assertSame('40', $this->balance($id), 'senken darf jeder mit dem Modul');

        $this->correct($id, '10', admin: true);
        self::assertSame('50', $this->balance($id));
    }

    public function testDieSchaltflaecheNurFuerAdministratoren(): void
    {
        foreach ([true => false, false => true] as $admin => $hidden) {
            $security = $this->createMock(Security::class);
            $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => (bool) $admin && 'ROLE_ADMIN' === $attribute);

            $operation = $this->createMock(DataContainerOperation::class);
            $operation->expects($hidden ? self::once() : self::never())->method('hide');

            (new VoucherIssueButtonListener($security))($operation);
        }
    }

    public function testBetraegeMitKommaPunktUndVorzeichen(): void
    {
        self::assertSame('12.5000', VoucherBackendController::amount('12,50'));
        self::assertSame('12.5000', VoucherBackendController::amount(' 12.5 '));
        self::assertSame('-5.0000', VoucherBackendController::amount('-5'));
        self::assertSame('1000.0000', VoucherBackendController::amount('1 000'));
    }

    public function testKeinBetragIstKeinBetrag(): void
    {
        foreach (['', 'zehn', '12,505', '1.000,00', '1e3', '--5', '12,'] as $typed) {
            self::assertNull(VoucherBackendController::amount($typed), $typed);
        }
    }

    public function testDasDatumAlsMitternachtDerInstallation(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Vienna');

        try {
            self::assertSame((new \DateTimeImmutable('2027-03-31 00:00:00', new \DateTimeZone('Europe/Vienna')))->getTimestamp(), VoucherBackendController::day('2027-03-31'));
            self::assertNull(VoucherBackendController::day(''), 'leer = unbefristet');
            self::assertFalse(VoucherBackendController::day('2027-02-30'));
            self::assertFalse(VoucherBackendController::day('31.03.2027'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    private function correct(int $id, string $amount, bool $admin): void
    {
        try {
            $request = Request::create('/contao?do=consho_voucher&key=correctVoucher&id='.$id, 'POST', ['consho_voucher_amount' => $amount, 'consho_voucher_note' => 'Kulanz']);
            $this->controller($request, $admin)->correct($this->dc($id));
            self::fail('POST leitet um');
        } catch (RedirectResponseException) {
        }
    }

    private function balance(int $id): string
    {
        return (string) BigDecimal::of((string) $this->connection->fetchOne('SELECT balance FROM tl_consho_voucher WHERE id = ?', [$id]))->toScale(0);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'table' => 'tl_consho_voucher',
            'id' => $id,
            default => null,
        });

        return $dc;
    }

    private function controller(Request $request, bool $admin, bool $module = true): VoucherBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute, mixed $subject = null): bool => match ($attribute) {
            ContaoCorePermissions::USER_CAN_ACCESS_MODULE => $module && VoucherBackendController::MODULE === $subject,
            'ROLE_ADMIN' => $admin,
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
        $system->method('__call')->willReturnCallback(static function (string $name): mixed {
            if ('loadLanguageFile' === $name) {
                require __DIR__.'/../../../contao/languages/en/tl_consho_voucher.php';
            }

            return '/contao?do=consho_voucher';
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $message : $system);

        return new VoucherBackendController(
            new VoucherLedger($this->connection),
            new ShopCurrencies($this->connection),
            $this->connection,
            $security,
            $framework,
            $requests,
            new Environment(new ArrayLoader()),
            $this->createMock(UrlGeneratorInterface::class),
            new MoneyFormatter(),
        );
    }
}
