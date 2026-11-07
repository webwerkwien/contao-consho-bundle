<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\InvalidRequestTokenException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\DeliveryNotificationBackendController;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * "Benachrichtigung erneut senden" at a delivery, through resend() itself:
 * the request token, the permission on the delivery's ORDER, the anonymised
 * order and the messages.
 */
class DeliveryNotificationBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<int> delivery ids passed to resendNotification() */
    private array $resent = [];

    private bool|null $result = true;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<int> order ids the permission was asked for */
    private array $checked = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'email' => 'kundin@example.org']);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'orderNumber' => 'WEB-8', 'email' => 'andere@example.org']);
        $this->connection->insert('tl_consho_delivery', ['id' => 30, 'pid' => 7, 'reportedAt' => 100]);
        $this->connection->insert('tl_consho_delivery', ['id' => 31, 'pid' => 8, 'reportedAt' => 100]);

        $GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationSent'] = 'Erneut %s an %s';
        $GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationFailed'] = 'Gescheitert %s';
        $GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationNothing'] = 'Nichts %s';
        $GLOBALS['TL_LANG']['tl_consho_delivery']['resendNotificationAnonymized'] = 'Anonymisiert %s';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testPerPostGehtDieBenachrichtigungErneutHinaus(): void
    {
        $target = $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(30)));

        self::assertSame('/contao?do=consho_order&ref=abc', $target);
        self::assertSame([30], $this->resent);
        self::assertSame([['addConfirmation', 'Erneut WEB-7 an kundin@example.org']], $this->messages);
    }

    /**
     * The permission is asked for the order the delivery belongs to — not
     * for the delivery (locked once reported) and not for another order.
     */
    public function testGeprueftWirdDasRechtAnDerBestellungDerLieferung(): void
    {
        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(31)));

        self::assertSame([8], $this->checked);
    }

    public function testOhneBearbeitungsrechtNichts(): void
    {
        self::assertInstanceOf(
            AccessDeniedHttpException::class,
            $this->thrownBy(fn () => $this->controller(Request::create('/contao', 'POST'), granted: false)->resend($this->dc(30))),
        );
        self::assertSame([], $this->resent);
    }

    /** As at the confirmation: a GET without a valid request token sends nothing. */
    public function testEinGetOhneGueltigesTokenSendetNichts(): void
    {
        self::assertInstanceOf(
            InvalidRequestTokenException::class,
            $this->thrownBy(fn () => $this->controller(Request::create('/contao?key=resendNotification', 'GET'), tokenValid: false)->resend($this->dc(30))),
        );
        self::assertSame([], $this->resent);
    }

    public function testEinGetMitGueltigemTokenSendet(): void
    {
        $this->redirectOf(fn () => $this->controller(Request::create('/contao?rt=ok', 'GET'))->resend($this->dc(30)));

        self::assertSame([30], $this->resent);
    }

    public function testEineUnbekannteLieferungIstNichtGefunden(): void
    {
        self::assertInstanceOf(
            NotFoundHttpException::class,
            $this->thrownBy(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(99))),
        );
        self::assertSame([], $this->resent);
    }

    /** D-5: no address, and the mail's link would issue a new token. */
    public function testEineAnonymisierteBestellungBekommtKeineMail(): void
    {
        $this->connection->update('tl_consho_order', ['anonymizedAt' => 1, 'email' => ''], ['id' => 7]);

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(30)));

        self::assertSame([], $this->resent);
        self::assertSame([['addError', 'Anonymisiert WEB-7']], $this->messages);
    }

    public function testEinGescheiterterVersandWirdGemeldet(): void
    {
        $this->result = false;

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(30)));

        self::assertSame([['addError', 'Gescheitert WEB-7']], $this->messages);
    }

    public function testOhneBenachrichtigungEinHinweis(): void
    {
        $this->result = null;

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(30)));

        self::assertSame([['addInfo', 'Nichts WEB-7']], $this->messages);
    }

    /** The customer typed the address; Contao prints messages as HTML. */
    public function testDieKundenadresseWirdInDerMeldungEscaped(): void
    {
        $this->connection->update('tl_consho_order', ['email' => '"<b>x</b>"@example.org'], ['id' => 7]);

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(30)));

        self::assertStringNotContainsString('<b>', $this->messages[0][1]);
    }

    private function thrownBy(\Closure $action): \Throwable|null
    {
        try {
            $action();
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
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

    private function controller(Request $request, bool $tokenValid = true, bool $granted = true): DeliveryNotificationBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $csrf = $this->createMock(ContaoCsrfTokenManager::class);
        $csrf->method('isTokenValid')->willReturn($tokenValid);

        $checked = &$this->checked;
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static function (string $attribute, mixed $subject) use ($granted, &$checked): bool {
            if (ContaoCorePermissions::DC_PREFIX.'tl_consho_order' !== $attribute || !$subject instanceof UpdateAction || 'tl_consho_order' !== $subject->getDataSource()) {
                return false;
            }

            $checked[] = (int) $subject->getCurrentId();

            return $granted;
        });

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): void {
            $messages[] = [$name, (string) $args[0]];
        });

        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_order&ref=abc');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => match ($class) {
                Message::class => $message,
                System::class => $system,
                default => throw new \LogicException($class),
            },
        );

        $resent = &$this->resent;
        $result = $this->result;
        $reported = $this->createMock(DeliveryReported::class);
        $reported->method('resendNotification')->willReturnCallback(static function (int $deliveryId) use (&$resent, $result): bool|null {
            $resent[] = $deliveryId;

            return $result;
        });

        return new DeliveryNotificationBackendController($this->connection, $security, $framework, $requests, $csrf, 'contao_csrf_token', $reported);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}
