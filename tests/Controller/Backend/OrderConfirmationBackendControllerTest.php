<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\InvalidRequestTokenException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\DataContainer;
use Contao\Message;
use Contao\PageModel;
use Contao\System;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\OrderConfirmationBackendController;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Token\TokenRepositoryInterface;

/**
 * "Erneut senden" (resend) on the order (C-9), through resend() itself.
 */
class OrderConfirmationBackendControllerTest extends TestCase
{
    private Connection $connection;

    private ResendSpy $confirmations;

    /** @var list<array{string, string}> */
    private array $messages = [];

    private int|null $rootId = 1;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'orderNumber' => 'WEB-7', 'shop' => 1, 'language' => 'de', 'email' => 'kundin@example.org']);
        $this->confirmations = new ResendSpy();

        $GLOBALS['TL_LANG']['tl_consho_order']['resendSent'] = 'Bestätigung %s an %s';
        $GLOBALS['TL_LANG']['tl_consho_order']['resendFailed'] = 'Gescheitert %s';
        $GLOBALS['TL_LANG']['tl_consho_order']['resendNoOrderPage'] = 'Keine Bestellseite %s';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testPerPostGehtDieBestaetigungMitNeuemLinkUndOhneBetreiberkopie(): void
    {
        $target = $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(7)));

        self::assertSame('/contao?do=consho_order&ref=abc', $target);
        self::assertCount(1, $this->confirmations->calls);

        [$orderId, $rootId, $viewUrl, $copyOperator] = $this->confirmations->calls[0];

        self::assertSame(7, $orderId);
        self::assertSame(1, $rootId);
        self::assertMatchesRegularExpression('#^https://example\.org/bestellung\.html\?consho_order=[0-9a-f]{64}$#', $viewUrl);
        self::assertFalse($copyOperator);
        self::assertSame([['addConfirmation', 'Bestätigung WEB-7 an kundin@example.org']], $this->messages);

        // C-10: the link goes out by mail, so a click on it confirms the
        // address.
        self::assertSame([true], $this->confirming);
    }

    /** @var list<bool> confirmsEmail of every token issued */
    private array $confirming = [];

    private function tokenRepository(): TokenRepositoryInterface
    {
        $repository = $this->createMock(TokenRepositoryInterface::class);
        $repository->method('store')->willReturnCallback(function (int $o, $p, string $h, int $c, int|null $e, bool $confirmsEmail): void {
            $this->confirming[] = $confirmsEmail;
        });

        return $repository;
    }

    /**
     * Like Contao's own resending (tl_opt_in): a GET without a valid request
     * token triggers nothing — otherwise any page an editor opens could send
     * a customer a mail.
     */
    public function testEinGetOhneGueltigesTokenSendetNichts(): void
    {
        self::assertInstanceOf(
            InvalidRequestTokenException::class,
            $this->thrownBy(fn () => $this->controller(Request::create('/contao?key=resendConfirmation', 'GET'), tokenValid: false)->resend($this->dc(7))),
        );
        self::assertSame([], $this->confirmations->calls);
    }

    public function testEinGetMitGueltigemTokenSendet(): void
    {
        $this->redirectOf(fn () => $this->controller(Request::create('/contao?rt=ok', 'GET'))->resend($this->dc(7)));

        self::assertCount(1, $this->confirmations->calls);
    }

    public function testOhneBearbeitungsrechtNichts(): void
    {
        self::assertInstanceOf(
            AccessDeniedHttpException::class,
            $this->thrownBy(fn () => $this->controller(Request::create('/contao', 'POST'), granted: false)->resend($this->dc(7))),
        );
        self::assertSame([], $this->confirmations->calls);
    }

    public function testOhneStartpunktKeineMailUndEinHinweis(): void
    {
        $this->rootId = null;

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(7)));

        self::assertSame([], $this->confirmations->calls);
        self::assertSame([['addError', 'Keine Bestellseite WEB-7']], $this->messages);
    }

    /** D-5: after anonymisation no mail and no new link. */
    public function testEineAnonymisierteBestellungBekommtKeineMail(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_order']['resendAnonymized'] = 'Anonymisiert %s';
        $this->connection->update('tl_consho_order', ['anonymizedAt' => 1, 'email' => ''], ['id' => 7]);

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(7)));

        self::assertSame([], $this->confirmations->calls);
        self::assertSame([['addError', 'Anonymisiert WEB-7']], $this->messages);
    }

    /**
     * The customer typed the address in the checkout, and Contao outputs
     * messages unfiltered as HTML.
     */
    public function testDieKundenadresseWirdInDerMeldungEscaped(): void
    {
        $this->connection->update('tl_consho_order', ['email' => '"<b>x</b>"@example.org'], ['id' => 7]);

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(7)));

        self::assertStringNotContainsString('<b>', $this->messages[0][1]);
    }

    public function testEinGescheiterterVersandWirdGemeldet(): void
    {
        $this->confirmations->result = false;

        $this->redirectOf(fn () => $this->controller(Request::create('/contao', 'POST'))->resend($this->dc(7)));

        self::assertSame([['addError', 'Gescheitert WEB-7']], $this->messages);
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

    private function controller(Request $request, bool $tokenValid = true, bool $granted = true): OrderConfirmationBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $csrf = $this->createMock(ContaoCsrfTokenManager::class);
        $csrf->method('isTokenValid')->willReturn($tokenValid);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($granted);

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): void {
            $messages[] = [$name, (string) $args[0]];
        });

        $root = $this->createMock(PageModel::class);
        $root->method('row')->willReturn(['consho_orderPage' => 245]);
        $orderPage = $this->createMock(PageModel::class);

        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(
            static fn (string $name, array $args): PageModel|null => 'findById' === $name ? [1 => $root, 245 => $orderPage][$args[0]] ?? null : null,
        );

        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_order&ref=abc');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(
            static fn (string $class): Adapter => match ($class) {
                Message::class => $message,
                PageModel::class => $pages,
                System::class => $system,
                default => throw new \LogicException($class),
            },
        );

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.org/bestellung.html');

        $rootId = $this->rootId;
        $source = $this->createMock(OrderConfirmationSourceInterface::class);
        $source->method('rootOf')->willReturn($rootId);

        return new OrderConfirmationBackendController(
            $this->connection,
            $security,
            $framework,
            $requests,
            $csrf,
            'contao_csrf_token',
            $this->confirmations,
            $source,
            new TokenGenerator($this->tokenRepository()),
            new ShopPageUrls($framework, new ShopResolver($framework), $urls),
        );
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}

final class ResendSpy implements OrderConfirmationInterface
{
    /** @var list<array{int, int, string, bool}> */
    public array $calls = [];

    public bool $result = true;

    public function send(int $orderId, int $rootId, string $viewUrl, int $now, bool $copyOperator = true): bool
    {
        $this->calls[] = [$orderId, $rootId, $viewUrl, $copyOperator];

        return $this->result;
    }
}
