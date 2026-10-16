<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Cancellation\SubscriptionContact;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\CancellationController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * „Verträge hier kündigen" (Abo-7, P58, P61; § 312k BGB). Echte Dienste auf
 * SQLite, nur die Mail ist ein Doppel.
 */
final class CancellationControllerTest extends TestCase
{
    private const SHOP = 1;
    private const OTHER_SHOP = 2;
    private const ROOT_PAGE = 5;
    private const PAGE = 11;
    private const URL = 'https://shop.example/kuendigen.html';

    private Connection $connection;

    private RateLimiterFactory $limiter;

    private UriSigner $signer;

    /** @var list<int> the order each confirmation went to */
    private array $confirmed = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_cancellation', 'tl_consho_order', 'tl_consho_order_item'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        // Kauf mit der alten Adresse, Folgebestellung mit der neuen.
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => self::SHOP, 'orderNumber' => 'WEB-00073', 'email' => 'Alt@Example.org', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 9, 'shop' => self::SHOP, 'subscription' => 1, 'orderNumber' => 'WEB-00091', 'email' => 'neu@example.org', 'createdAt' => 2]);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'shop' => self::OTHER_SHOP, 'orderNumber' => 'WEB-00080', 'email' => 'neu@example.org', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => 7, 'productName' => 'E-Book Kräuterkunde']);

        $end = time() + 200 * 86_400;
        $base = ['state' => 'active', 'phase' => 'minimum', 'renewal' => 'indefinite', 'termLength' => 1, 'termUnit' => 'year', 'noticeDays' => 30, 'startedAt' => $end - 365 * 86_400, 'minimumEndsAt' => $end];
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'shop' => self::SHOP, 'subscriptionNumber' => 'WEB-00073-1', 'firstOrder' => 7, 'orderItem' => 3] + $base);
        $this->connection->insert('tl_consho_subscription', ['id' => 2, 'shop' => self::OTHER_SHOP, 'subscriptionNumber' => 'WEB-00080-1', 'firstOrder' => 8] + $base);

        $this->limiter = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage());
        $this->signer = new UriSigner('test-secret', CancellationForm::HASH_PARAM, CancellationForm::EXPIRES_PARAM);
    }

    public function testZuerstNurDerKnopf(): void
    {
        $data = $this->render();

        self::assertFalse($data['form']);
        self::assertNull($data['result']);
    }

    public function testDerKnopfFuehrtZurBestaetigungsseite(): void
    {
        self::assertTrue($this->render('?consho_cancel=1')['form']);
    }

    /**
     * Gefunden über die Adresse irgendeiner Bestellung des Abos — bestätigt
     * wird an die des Abos (die neueste), nie an die eingegebene.
     */
    public function testGefundenWirdGespeichertUndZurSigniertenErgebnisseiteUmgeleitet(): void
    {
        $redirect = $this->post(['consho_cancellation_subscriptionNumber' => ' WEB-00073-1 ', 'consho_cancellation_email' => 'alt@example.org', 'consho_cancellation_name' => 'Erika Muster']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        $location = (string) $redirect->getResponse()->headers->get('Location');
        self::assertSame(Response::HTTP_SEE_OTHER, $redirect->getResponse()->getStatusCode());
        self::assertStringStartsWith(self::URL.'?consho_cancellation=1&consho_expires=', $location);
        self::assertStringContainsString('&consho_hash=', $location);
        self::assertTrue($this->signer->check($location));

        $row = $this->connection->fetchAssociative('SELECT source, kind, name, email, state FROM tl_consho_cancellation');
        self::assertSame(['source' => 'form', 'kind' => 'ordinary', 'name' => 'Erika Muster', 'email' => 'neu@example.org', 'state' => 'effective'], $row);
        self::assertSame([9], $this->confirmed);

        // § 312k Abs. 3: die Ergebnisseite zeigt die Erklärung mit Zeitpunkt.
        $result = $this->render(substr($location, \strlen(self::URL)))['result'];
        self::assertIsArray($result);
        self::assertSame('WEB-00073-1-K1', $result['number']);
        self::assertSame('E-Book Kräuterkunde', $result['product']);
        self::assertMatchesRegularExpression('/\d{2}:\d{2}:\d{2}/', $result['receivedAt'], 'mit Uhrzeit');
        self::assertNotSame('', $result['effectiveAt']);
        self::assertTrue($result['confirmed']);
    }

    /**
     * L4 (Michael, 2026-09-30: „90 Tage"): danach sagt die Seite, dass sie
     * abgelaufen ist, statt die Erklärung zu zeigen.
     */
    public function testNach90TagenAbgelaufen(): void
    {
        $this->post(['consho_cancellation_subscriptionNumber' => 'WEB-00073-1', 'consho_cancellation_email' => 'neu@example.org', 'consho_cancellation_name' => 'E']);

        $expired = $this->signer->sign(self::URL.'?consho_cancellation=1', time() - 60);
        $data = $this->render(substr($expired, \strlen(self::URL)));

        self::assertNull($data['result']);
        self::assertTrue($data['expired']);

        // Noch gültig: die Erklärung, nicht „abgelaufen".
        $valid = $this->signer->sign(self::URL.'?consho_cancellation=1', time() + 60);
        $data = $this->render(substr($valid, \strlen(self::URL)));
        self::assertIsArray($data['result']);
        self::assertFalse($data['expired']);
    }

    public function testOhneSignaturKeineErklaerung(): void
    {
        $this->post(['consho_cancellation_subscriptionNumber' => 'WEB-00073-1', 'consho_cancellation_email' => 'neu@example.org', 'consho_cancellation_name' => 'E']);

        self::assertNull($this->render('?consho_cancellation=1')['result']);
        self::assertNull($this->render('?consho_cancellation=1&consho_hash=falsch')['result']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mismatches(): iterable
    {
        yield 'Nummer gibt es nicht' => ['WEB-99999-1', 'neu@example.org'];
        yield 'Adresse passt nicht' => ['WEB-00073-1', 'jemand@example.org'];
        yield 'anderer Shop' => ['WEB-00080-1', 'neu@example.org'];
    }

    /**
     * @dataProvider mismatches
     */
    public function testNichtGefundenOhneUnterschiedUndOhneMail(string $number, string $email): void
    {
        $data = $this->submit(['consho_cancellation_subscriptionNumber' => $number, 'consho_cancellation_email' => $email, 'consho_cancellation_name' => 'E']);

        self::assertSame('notFound', $data['error']);
        self::assertTrue($data['form']);
        self::assertSame($number, $data['input']['subscriptionNumber'], 'das Eingegebene bleibt stehen');
        self::assertSame([], $this->confirmed);
    }

    public function testDerSechsteVersuchWirdAbgewiesen(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame('notFound', $this->submit(['consho_cancellation_subscriptionNumber' => 'X', 'consho_cancellation_email' => 'a@b.c', 'consho_cancellation_name' => 'E'])['error']);
        }

        $data = $this->submit(['consho_cancellation_subscriptionNumber' => 'WEB-00073-1', 'consho_cancellation_email' => 'neu@example.org', 'consho_cancellation_name' => 'E']);

        self::assertSame('tooMany', $data['error']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function fehler(): iterable
    {
        $found = ['consho_cancellation_subscriptionNumber' => 'WEB-00073-1', 'consho_cancellation_email' => 'neu@example.org'];

        yield 'ohne Name' => [$found + ['consho_cancellation_name' => ''], 'missing'];
        yield 'ohne Abonummer' => [['consho_cancellation_subscriptionNumber' => '', 'consho_cancellation_email' => 'neu@example.org', 'consho_cancellation_name' => 'E'], 'missing'];
        yield 'außerordentlich ohne Grund' => [$found + ['consho_cancellation_name' => 'E', 'consho_cancellation_kind' => 'extraordinary'], 'reasonMissing'];
        yield 'kein Datum' => [$found + ['consho_cancellation_name' => 'E', 'consho_cancellation_date' => '31.12.2027'], 'dateInvalid'];
        yield 'erfundene Art' => [$found + ['consho_cancellation_name' => 'E', 'consho_cancellation_kind' => 'sofort'], 'missing'];
    }

    /**
     * @param array<string, string> $post
     *
     * @dataProvider fehler
     */
    public function testFehlerBleibenAufDerBestaetigungsseite(array $post, string $error): void
    {
        $data = $this->submit($post);

        self::assertSame($error, $data['error']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
    }

    public function testBereitsGekuendigtNenntDasEnde(): void
    {
        $this->connection->update('tl_consho_subscription', ['endsAt' => (new \DateTimeImmutable('2027-03-31 10:00'))->getTimestamp()], ['id' => 1]);

        $data = $this->submit(['consho_cancellation_subscriptionNumber' => 'WEB-00073-1', 'consho_cancellation_email' => 'neu@example.org', 'consho_cancellation_name' => 'E']);

        self::assertSame('alreadyEnding', $data['error']);
        self::assertStringContainsString('2027', $data['until']);
    }

    public function testAusserordentlichMitGrundUndWunschtag(): void
    {
        $this->post([
            'consho_cancellation_subscriptionNumber' => 'WEB-00073-1', 'consho_cancellation_email' => 'neu@example.org', 'consho_cancellation_name' => 'E',
            'consho_cancellation_kind' => 'extraordinary', 'consho_cancellation_reason' => 'Umzug', 'consho_cancellation_date' => '2027-01-31',
        ]);

        $row = $this->connection->fetchAssociative('SELECT kind, reason, state, requestedAt, effectiveAt FROM tl_consho_cancellation');

        self::assertSame('extraordinary', $row['kind'] ?? null);
        self::assertSame('Umzug', $row['reason']);
        self::assertSame('received', $row['state']);
        self::assertSame((new SubscriptionCalendar())->startOfDay('2027-01-31'), (int) $row['requestedAt']);
        self::assertNull($row['effectiveAt']);
    }

    /**
     * @return array<string, mixed>
     */
    private function render(string $query = ''): array
    {
        $template = $this->template();
        $this->controller()->respond($template, $this->model(), $this->request('GET', [], $query));

        return $template->getData();
    }

    /**
     * @param array<string, string> $post
     *
     * @return array<string, mixed>
     */
    private function submit(array $post): array
    {
        $template = $this->template();
        $this->controller()->respond($template, $this->model(), $this->request('POST', ['consho_cancellation_action' => 'confirm'] + $post));

        return $template->getData();
    }

    /**
     * @param array<string, string> $post
     */
    private function post(array $post): \Throwable|null
    {
        try {
            $this->submit($post);
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function template(): FragmentTemplate
    {
        return new FragmentTemplate('content_element/consho_cancellation', static fn (): Response => new Response());
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method, array $post, string $query = ''): Request
    {
        $request = Request::create(self::URL.$query, $method, $post, [], [], ['REMOTE_ADDR' => '203.0.113.7']);
        $request->attributes->set('pageModel', $this->pageModel(self::PAGE));

        return $request;
    }

    private function model(): ContentModel
    {
        $model = $this->createMock(ContentModel::class);
        $model->method('row')->willReturn(['id' => 44]);

        return $model;
    }

    private function controller(): CancellationController&TestableCancellationElement
    {
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

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('cancellationReceived')->willReturnCallback(function (int $orderId): bool {
            $this->confirmed[] = $orderId;

            return true;
        });

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn(null);

        $calendar = new SubscriptionCalendar();
        $recorder = new CancellationRecorder($this->connection, $lock, new CancellationTerm($calendar), $mail, $links, new SubscriptionContact($this->connection));

        return new class(
            new ShopResolver($this->framework()),
            $this->connection,
            new CancellationForm($this->connection, $recorder, $calendar, $this->signer),
            $this->limiter,
        ) extends CancellationController implements TestableCancellationElement {
            public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response
            {
                return $this->getResponse($template, $model, $request);
            }
        };
    }

    private function framework(): ContaoFramework
    {
        $pages = [self::ROOT_PAGE => $this->pageModel(self::ROOT_PAGE, ['consho_shop' => self::SHOP, 'consho_currency' => 'EUR'])];

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $parameters): PageModel|null => $pages[(int) ($parameters[0] ?? 0)] ?? null,
        );

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([['id', self::SHOP], ['currency', 'EUR'], ['country', 'AT']]);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $parameters): ShopModel|null => self::SHOP === (int) ($parameters[0] ?? 0) ? $shop : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pageAdapter],
            [ShopModel::class, $shopAdapter],
        ]);

        return $framework;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pageModel(int $id, array $row = []): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            ['type', self::ROOT_PAGE === $id ? 'root' : 'regular'],
            ['rootId', self::ROOT_PAGE],
            ['language', 'de'],
        ]);
        $page->method('row')->willReturn($row + ['id' => $id]);

        return $page;
    }
}

interface TestableCancellationElement
{
    public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response;
}
