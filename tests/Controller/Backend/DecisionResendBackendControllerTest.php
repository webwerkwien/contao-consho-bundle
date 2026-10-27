<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\InvalidRequestTokenException;
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
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDecision;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\DecisionResendBackendController;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * E10: "Entscheidung erneut senden" (resend decision) — POST, right on the
 * cancellation, the answer of CancellationDecision::resend() as a message.
 */
final class DecisionResendBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<int> */
    private array $resent = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_cancellation');
        $this->connection->insert('tl_consho_cancellation', ['id' => 5, 'pid' => 1, 'number' => 'WEB-1-1-K1']);

        require __DIR__.'/../../../contao/languages/de/tl_consho_cancellation.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    /**
     * @return iterable<string, array{bool|null, string, string}>
     */
    public static function antworten(): iterable
    {
        yield 'gesendet' => [true, 'addConfirmation', 'Die Entscheidung über WEB-1-1-K1 ging erneut an den Kunden.'];
        yield 'gescheitert' => [false, 'addError', 'Die Entscheidung über WEB-1-1-K1 konnte nicht gesendet werden. Den Grund nennt das Systemlog.'];
        yield 'nichts zu senden' => [null, 'addInfo', 'Für WEB-1-1-K1 gibt es nichts erneut zu senden: noch nicht entschieden, oder die Mail ist schon hinausgegangen.'];
    }

    /**
     * @dataProvider antworten
     */
    public function testDieAntwortWirdZurMeldung(bool|null $result, string $kind, string $text): void
    {
        try {
            // resend() returns never: it redirects or throws.
            $this->controller(Request::create('/contao?key=resendDecision&id=5', 'POST'), $result)->resend($this->dc());
        } catch (RedirectResponseException) {
        }

        self::assertSame([5], $this->resent);
        self::assertSame([[$kind, $text]], $this->messages);
    }

    public function testEinGetOhneTokenSendetNichts(): void
    {
        $this->expectException(InvalidRequestTokenException::class);

        try {
            $this->controller(Request::create('/contao?key=resendDecision&id=5'), true)->resend($this->dc());
        } finally {
            self::assertSame([], $this->resent);
        }
    }

    public function testOhneRechtNichts(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?key=resendDecision&id=5', 'POST'), true, false)->resend($this->dc());
    }

    private function controller(Request $request, bool|null $result, bool $granted = true): DecisionResendBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => $granted && ContaoCorePermissions::DC_PREFIX.'tl_consho_cancellation' === $attribute);

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            $messages[] = [$name, (string) $args[0]];

            return '';
        });
        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_subscription');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $message : $system);

        $csrf = $this->createMock(ContaoCsrfTokenManager::class);
        $csrf->method('isTokenValid')->willReturn(false);

        $decision = $this->createMock(CancellationDecision::class);
        $decision->method('resend')->willReturnCallback(function (int $id) use ($result): bool|null {
            $this->resent[] = $id;

            return $result;
        });

        return new DecisionResendBackendController($this->connection, $security, $framework, $requests, $csrf, 'contao_csrf_token', $decision);
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 5 : null);

        return $dc;
    }
}
