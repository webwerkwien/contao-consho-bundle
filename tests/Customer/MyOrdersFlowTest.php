<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;
use Webwerkwien\ContaoConshoBundle\Customer\GuestOrderConfirmation;
use Webwerkwien\ContaoConshoBundle\Customer\MemberOrders;
use Webwerkwien\ContaoConshoBundle\Customer\MyOrdersFlow;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Tests\Confirmation\FakeOptIn;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ErrorSpyLogger;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\FakeConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\SpyTransport;
use Webwerkwien\ContaoConshoBundle\Token\DbalTokenRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;

require_once __DIR__.'/../Confirmation/EmailConfirmationTest.php';

/**
 * D-4, K3 — was „Meine Bestellungen" mit einer Anfrage macht. Die Stelle,
 * an der Mitglied, Bestellnummer aus der Adresse und Token zusammenkommen
 * (Fable-Review D-4, B1).
 */
final class MyOrdersFlowTest extends TestCase
{
    private const HERE = 'https://shop.test/konto.html';

    private const ORDER_PAGE = 'https://shop.test/bestellung.html';

    private const NOW = 1_790_000_000;

    private Connection $connection;

    private FakeOptIn $optIn;

    private SpyTransport $transport;

    private RateLimiterFactory $limiter;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, email TEXT NOT NULL DEFAULT '', consho_confirmedEmail TEXT NOT NULL DEFAULT '', consho_confirmedEmailAt INTEGER NULL)");
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_token');

        $this->connection->insert('tl_member', ['id' => 5, 'email' => 'erika@example.org']);
        $this->connection->insert('tl_member', ['id' => 6, 'email' => 'andere@example.org']);
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'member' => 5, 'orderNumber' => 'WEB-1', 'email' => 'erika@example.org', 'createdAt' => 100, 'grandTotal' => '22.8000']);
        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 1, 'member' => 0, 'orderNumber' => 'WEB-2', 'email' => 'erika@example.org', 'createdAt' => 200]);
        $this->connection->insert('tl_consho_order', ['id' => 3, 'shop' => 1, 'member' => 6, 'orderNumber' => 'WEB-3', 'email' => 'andere@example.org', 'createdAt' => 300]);

        $this->optIn = new FakeOptIn();
        $this->transport = new SpyTransport();
        $this->limiter = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '1 hour'], new InMemoryStorage());
    }

    /** (a) Eine fremde Bestellung: kein Token, zurück mit „nicht gefunden". */
    public function testEineFremdeBestellungWirdNichtGeoeffnet(): void
    {
        $result = $this->handle(Request::create(self::HERE.'?consho_open=3'), 5);

        self::assertSame(['redirect' => self::HERE.'?consho_my_orders=notFound'], $result);
        self::assertSame(0, $this->tokenCount());
    }

    /** Gastbestellung unter der eigenen Adresse — vor der Bestätigung ebenso. */
    public function testDieGastbestellungErstNachBestaetigung(): void
    {
        self::assertSame(['redirect' => self::HERE.'?consho_my_orders=notFound'], $this->handle(Request::create(self::HERE.'?consho_open=2'), 5));
        self::assertSame(0, $this->tokenCount());
    }

    /** (b) Die eigene: Token für eine Stunde, bestätigt keine Adresse. */
    public function testDieEigeneBestellungOeffnetMitKurzemToken(): void
    {
        $result = $this->handle(Request::create(self::HERE.'?consho_open=1'), 5);

        self::assertArrayHasKey('redirect', $result);
        self::assertMatchesRegularExpression('#^'.preg_quote(self::ORDER_PAGE, '#').'\?consho_order=[0-9a-f]{64}$#', $result['redirect']);

        $token = $this->connection->fetchAssociative('SELECT pid, purpose, expiresAt, confirmsEmail FROM tl_consho_order_token');
        self::assertIsArray($token);
        self::assertSame([1, 'viewOrder', self::NOW + 3600, 0], [(int) $token['pid'], $token['purpose'], (int) $token['expiresAt'], (int) $token['confirmsEmail']]);
    }

    public function testAbgemeldetKeinTokenUndNichtsZuSehen(): void
    {
        self::assertSame(['redirect' => self::HERE.'?consho_my_orders=notFound'], $this->handle(Request::create(self::HERE.'?consho_open=1'), 0));
        self::assertSame(0, $this->tokenCount());

        // (e) Abgemeldet ohne Link: nichts.
        self::assertSame(['view' => null], $this->handle(Request::create(self::HERE), 0));
    }

    public function testOhneBestellseiteKeinToken(): void
    {
        self::assertSame(['redirect' => self::HERE.'?consho_my_orders=noOrderPage'], $this->handle(Request::create(self::HERE.'?consho_open=1'), 5, orderPage: null));
        self::assertSame(0, $this->tokenCount());
    }

    /** (c) + (d): Das Öffnen des Links bestätigt nichts, erst der POST. */
    public function testDerLinkBestaetigtNichtErstDerKnopf(): void
    {
        $redirect = $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'request']), 5);
        self::assertSame(['redirect' => self::HERE.'?consho_my_orders=sent'], $redirect);

        $link = $this->linkInMail();
        self::assertStringStartsWith(self::HERE.'?consho_confirm=cogo-', $link);
        self::assertStringEndsWith('#consho-confirm', $link, 'der Link landet beim Kasten, nicht über anderen Formularen');
        $token = $this->optIn->tokens[0]->getIdentifier();

        $view = $this->handle(Request::create(self::HERE.'?consho_confirm='.$token), 5)['view'] ?? null;
        self::assertIsArray($view);
        self::assertSame('pending', $view['confirm']);
        self::assertFalse($this->optIn->tokens[0]->isConfirmed(), 'GET bestätigt nie — Mailfilter öffnen Links');
        self::assertSame(['WEB-1'], array_column($view['orders'], 'number'));

        $confirmed = $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'confirm', MyOrdersFlow::TOKEN_FIELD => $token]), 5);
        self::assertSame(['redirect' => self::HERE.'?consho_my_orders=confirmed'], $confirmed);

        $view = $this->handle(Request::create(self::HERE.'?consho_my_orders=confirmed'), 5)['view'] ?? null;
        self::assertIsArray($view);
        self::assertTrue($view['guestConfirmed']);
        self::assertSame(['WEB-2', 'WEB-1'], array_column($view['orders'], 'number'));
        self::assertSame('confirmed', $view['notice']);
    }

    /** Die Bestätigung aus einem fremden Konto geht zurück zum Link, der sagt warum. */
    public function testDieBestaetigungAusEinemFremdenKontoGiltNicht(): void
    {
        $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'request']), 5);
        $token = $this->optIn->tokens[0]->getIdentifier();

        $result = $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'confirm', MyOrdersFlow::TOKEN_FIELD => $token]), 6, 'andere@example.org');

        self::assertSame(['redirect' => self::HERE.'?consho_confirm='.$token.'#consho-confirm'], $result);
        self::assertFalse($this->optIn->tokens[0]->isConfirmed());
        self::assertSame('otherAccount', $this->handle(Request::create(self::HERE.'?consho_confirm='.$token), 6, 'andere@example.org')['view']['confirm'] ?? null);
    }

    /** Review S1: ohne Namen, und begrenzt je Konto UND je Adresse. */
    public function testDieMailOhneNamenUndBegrenztJeAdresse(): void
    {
        $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'request']), 5);
        $html = (string) $this->transport->sent[0]->getHtmlBody();
        self::assertStringContainsString('Guten Tag,', $html);
        self::assertSame('', $this->transport->sent[0]->getTo()[0]->getName());

        $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'request']), 5);
        $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'request']), 5);

        // Ein neues Konto mit derselben Adresse hilft nicht weiter.
        self::assertSame(['redirect' => self::HERE.'?consho_my_orders=tooMany'], $this->handle(Request::create(self::HERE, 'POST', [MyOrdersFlow::ACTION => 'request']), 6, 'Erika@example.org'));
        self::assertCount(3, $this->transport->sent);
    }

    /** Review S3: nur bekannte Hinweise, kein Freitext aus der Adresse. */
    public function testNurBekannteHinweise(): void
    {
        self::assertSame('', $this->handle(Request::create(self::HERE.'?consho_my_orders=Rufen+Sie+0900+an'), 5)['view']['notice'] ?? null);
        self::assertSame('sent', $this->handle(Request::create(self::HERE.'?consho_my_orders=sent'), 5)['view']['notice'] ?? null);
    }

    /**
     * @return array{redirect: string}|array{view: array<string, mixed>|null}
     */
    private function handle(Request $request, int $memberId, string $email = 'erika@example.org', string|null $orderPage = self::ORDER_PAGE): array
    {
        return $this->flow()->handle($request, 1, $orderPage, $memberId, $email, 'de', self::HERE, self::NOW);
    }

    private function flow(): MyOrdersFlow
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');
        $translator = new ContaoLikeTranslator();
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        $factory = new class($this->transport) implements MailTransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function create(array $row): TransportInterface
            {
                return $this->transport;
            }
        };

        $orders = new MemberOrders($this->connection);

        return new MyOrdersFlow(
            $orders,
            new GuestOrderConfirmation(new EmailConfirmation($this->optIn, new FakeConfirmationSource(), $factory, new MailRenderer($twig, $translator), new ErrorSpyLogger()), $orders),
            new TokenGenerator(new DbalTokenRepository($this->connection)),
            $this->limiter,
        );
    }

    private function tokenCount(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_order_token');
    }

    private function linkInMail(): string
    {
        preg_match('#href="([^"]*consho_confirm[^"]*)"#', (string) $this->transport->sent[0]->getHtmlBody(), $match);

        return html_entity_decode($match[1] ?? '');
    }
}
