<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\WithdrawalController;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Withdrawal\DbalWithdrawalRepository;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Das Element „Vertrag widerrufen" (D-2, W1, W2, E4, E6). Echte Dienste auf
 * SQLite, nur die Mail ist ein Doppel.
 */
final class WithdrawalControllerTest extends TestCase
{
    private const SHOP = 1;
    private const OTHER_SHOP = 2;
    private const ROOT_PAGE = 5;
    private const PAGE = 11;
    private const URL = 'https://shop.example/widerruf.html';

    private Connection $connection;

    private RateLimiterFactory $limiter;

    /** @var list<int> the order of each confirmation that went out */
    private array $confirmed = [];

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection(); // the withdrawal locks its order (K2)
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');
        WithdrawalSchema::create($this->connection);

        $this->connection->insert('tl_consho_shop', ['id' => self::SHOP, 'operatorAddress' => 'office@shop.example']);
        $this->connection->insert('tl_consho_shop', ['id' => self::OTHER_SHOP]);
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => self::SHOP, 'orderNumber' => 'WEB-00073', 'email' => 'Kundin@Example.org', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'shop' => self::OTHER_SHOP, 'orderNumber' => 'WEB-00080', 'email' => 'kundin@example.org', 'grandTotal' => '10']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Wanderstock', 'quantity' => '1']);

        $this->limiter = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage());
    }

    public function testZuerstNurVertragWiderrufen(): void
    {
        $data = $this->render();

        self::assertFalse($data['form']);
        self::assertNull($data['done']);
    }

    public function testGefundenWirdGespeichertUndAnDieAdresseDerBestellungBestaetigt(): void
    {
        $redirect = $this->post(['consho_withdrawal_orderNumber' => ' WEB-00073 ', 'consho_withdrawal_email' => 'kundin@example.org', 'consho_withdrawal_name' => 'Erika Muster', 'consho_withdrawal_part' => 'nur der Stock']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_withdrawal=done&consho_withdrawal_number=WEB-00073-W1', $redirect->getResponse()->headers->get('Location'));

        $row = $this->connection->fetchAssociative('SELECT source, scope, part, email FROM tl_consho_withdrawal');

        self::assertSame(['source' => 'form', 'scope' => 'part', 'part' => 'nur der Stock', 'email' => 'Kundin@Example.org'], $row, 'die Adresse der Bestellung, nicht die eingegebene');
        self::assertSame([7], $this->confirmed);
    }

    /**
     * W2: dieselbe Meldung, ob die Nummer fehlt, die Adresse nicht passt
     * oder die Bestellung zu einem anderen Shop gehört — und keine Mail.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function mismatches(): iterable
    {
        yield 'Nummer gibt es nicht' => ['WEB-99999', 'kundin@example.org'];
        yield 'Adresse passt nicht' => ['WEB-00073', 'jemand@example.org'];
        yield 'anderer Shop' => ['WEB-00080', 'kundin@example.org'];
    }

    /**
     * @dataProvider mismatches
     */
    public function testNichtGefundenOhneUnterschiedUndOhneMail(string $number, string $email): void
    {
        $data = $this->submit(['consho_withdrawal_orderNumber' => $number, 'consho_withdrawal_email' => $email, 'consho_withdrawal_name' => 'E']);

        self::assertSame('notFound', $data['error']);
        self::assertTrue($data['form']);
        self::assertSame($number, $data['input']['orderNumber'], 'das Eingegebene bleibt stehen');
        self::assertSame([], $this->confirmed);
    }

    /** E4: 5 Versuche je IP in 15 Minuten — gezählt wird jeder, nicht nur die Fehlgriffe. */
    public function testDerSechsteVersuchWirdAbgewiesen(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame('notFound', $this->submit(['consho_withdrawal_orderNumber' => 'X', 'consho_withdrawal_email' => 'a@b.c', 'consho_withdrawal_name' => 'E'])['error']);
        }

        $data = $this->submit(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => 'kundin@example.org', 'consho_withdrawal_name' => 'E']);

        self::assertSame('tooMany', $data['error']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    public function testFehlendeAngaben(): void
    {
        self::assertSame('missing', $this->submit(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => '', 'consho_withdrawal_name' => 'E'])['error']);
    }

    public function testNachFristendeMitDerAdresseDesShops(): void
    {
        $shipped = time() - 40 * 86400;
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7, 'shippedAt' => $shipped, 'reportedAt' => $shipped]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);

        $data = $this->submit(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => 'kundin@example.org', 'consho_withdrawal_name' => 'E']);

        self::assertSame('closed', $data['error']);
        self::assertSame('office@shop.example', $data['contact']);
    }

    public function testDieBestaetigungNachDemUmleiten(): void
    {
        $data = $this->render('?consho_withdrawal=done&consho_withdrawal_number=WEB-00073-W1');

        self::assertSame('done', $data['done']);
        self::assertSame('WEB-00073-W1', $data['number']);
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
        $this->controller()->respond($template, $this->model(), $this->request('POST', ['consho_withdrawal_action' => 'confirm'] + $post));

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
        return new FragmentTemplate('content_element/consho_withdrawal', static fn (): Response => new Response());
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
        $model->method('row')->willReturn(['id' => 43]);

        return $model;
    }

    private function controller(): WithdrawalController&TestableWithdrawalElement
    {
        $windows = new WithdrawalWindowReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('withdrawalReceived')->willReturnCallback(function (int $orderId): bool {
            $this->confirmed[] = $orderId;

            return true;
        });

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn(null);

        return new class(
            new ShopResolver($this->framework()),
            $this->connection,
            new WithdrawalRecorder($this->connection, $windows, new DbalWithdrawalRepository($this->connection), $mail, $links),
            $this->limiter,
        ) extends WithdrawalController implements TestableWithdrawalElement {
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

interface TestableWithdrawalElement
{
    public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response;
}
