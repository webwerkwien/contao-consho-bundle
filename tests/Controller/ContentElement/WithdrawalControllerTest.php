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

    /** @var list<array{int, string, string}> shop, address and language of each unassigned confirmation */
    private array $unassigned = [];

    private bool $mailWorks = true;

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
        self::assertSame(self::URL.'?consho_withdrawal=done', $redirect->getResponse()->headers->get('Location'), 'ohne Nummer (Review WA, S1)');

        $row = $this->connection->fetchAssociative('SELECT source, scope, part, email FROM tl_consho_withdrawal');

        self::assertSame(['source' => 'form', 'scope' => 'part', 'part' => 'nur der Stock', 'email' => 'Kundin@Example.org'], $row, 'die Adresse der Bestellung, nicht die eingegebene');
        self::assertSame([7], $this->confirmed);
    }

    /**
     * WA, A1 (Michael, 2026-10-22): Passen Nummer und Adresse nicht — die
     * Nummer gibt es nicht, die Adresse passt nicht, die Bestellung gehört zu
     * einem anderen Shop —, wird der Widerruf trotzdem gespeichert, ohne
     * Bestellung, und an die eingegebene Adresse bestätigt. Bis dahin: „nicht
     * gefunden", nichts gespeichert (W2).
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
    public function testNichtGefundenWirdNichtZugeordnetGespeichert(string $number, string $email): void
    {
        $redirect = $this->post(['consho_withdrawal_orderNumber' => $number, 'consho_withdrawal_email' => $email, 'consho_withdrawal_name' => 'E', 'consho_withdrawal_message' => 'bitte']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_withdrawal=done', $redirect->getResponse()->headers->get('Location'), 'dieselbe Antwort wie bei einem Treffer');

        $row = $this->connection->fetchAssociative('SELECT pid, shop, orderReference, email, message FROM tl_consho_withdrawal');

        self::assertSame(
            ['pid' => 0, 'shop' => self::SHOP, 'orderReference' => $number, 'email' => $email, 'message' => 'bitte'],
            \is_array($row) ? ['pid' => (int) $row['pid'], 'shop' => (int) $row['shop'], 'orderReference' => $row['orderReference'], 'email' => $row['email'], 'message' => $row['message']] : null,
        );
        self::assertSame([], $this->confirmed, 'keine Mail über eine Bestellung');
        self::assertSame([[self::SHOP, $email, 'de']], $this->unassigned);
    }

    /** E4: 5 Versuche je IP in 15 Minuten — gezählt wird jeder, auch die gespeicherten. */
    public function testDerSechsteVersuchWirdAbgewiesen(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertInstanceOf(RedirectResponseException::class, $this->post(['consho_withdrawal_orderNumber' => 'X', 'consho_withdrawal_email' => 'a@example.net', 'consho_withdrawal_name' => 'E']));
        }

        $data = $this->submit(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => 'kundin@example.org', 'consho_withdrawal_name' => 'E']);

        self::assertSame('tooMany', $data['error']);
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    public function testFehlendeAngaben(): void
    {
        self::assertSame('missing', $this->submit(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => '', 'consho_withdrawal_name' => 'E'])['error']);
    }

    /** An die eingegebene Adresse geht jetzt die Bestätigung — sie muss eine sein. */
    public function testUngueltigeAdresse(): void
    {
        $data = $this->submit(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => 'keine-adresse', 'consho_withdrawal_name' => 'E']);

        self::assertSame('emailInvalid', $data['error']);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    /**
     * Review WA, S4: was nicht in die Spalte passt, darf auf MySQL nicht als
     * 500 enden — ein langer Name wird gekürzt. Die Adresse braucht das nicht:
     * FILTER_VALIDATE_EMAIL nimmt höchstens 254 Zeichen.
     */
    public function testLangerNameWirdGekuerzt(): void
    {
        // Gültig gebaut (Labels höchstens 63 Zeichen), damit nur die Länge
        // entscheidet (zweites Review WA): 254 geht durch, 255 nicht.
        $address = static fn (int $last): string => str_repeat('a', 64).'@'.implode('.', [str_repeat('b', 63), str_repeat('c', 63), str_repeat('d', $last)]).'.at';
        self::assertSame(254, \strlen($address(58)));
        self::assertNotFalse(filter_var($address(58), \FILTER_VALIDATE_EMAIL));
        self::assertSame('emailInvalid', $this->submit(['consho_withdrawal_orderNumber' => 'X', 'consho_withdrawal_email' => $address(59), 'consho_withdrawal_name' => 'E'])['error'], 'über 254 Zeichen keine Adresse');

        self::assertInstanceOf(RedirectResponseException::class, $this->post(['consho_withdrawal_orderNumber' => 'X', 'consho_withdrawal_email' => 'e@example.org', 'consho_withdrawal_name' => str_repeat('ä', 300)]));

        self::assertSame(255, mb_strlen((string) $this->connection->fetchOne('SELECT name FROM tl_consho_withdrawal')));
    }

    /**
     * WA, B1: Nach dem Fenster wird gespeichert und markiert, nicht mehr
     * abgelehnt — die Antwort an den Kunden ist dieselbe wie im Fenster.
     */
    public function testNachFensterendeGespeichertWieImFenster(): void
    {
        $shipped = time() - 40 * 86400;
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => 7, 'shippedAt' => $shipped, 'reportedAt' => $shipped]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '1']);

        $redirect = $this->post(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => 'kundin@example.org', 'consho_withdrawal_name' => 'E']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_withdrawal=done', $redirect->getResponse()->headers->get('Location'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT afterWindow FROM tl_consho_withdrawal'));
    }

    /**
     * Review WA, S1 (Michael, 2026-10-22: „wie empfohlen"): Lehnt die Bestellung
     * ab — hier Frist 0 —, antwortet das Formular nicht mehr „nicht mehr
     * möglich": das verriet einem Fremden, dass es die Bestellung gibt. Es
     * speichert nicht zugeordnet, wie ohne Treffer; der Betreiber ordnet zu
     * und lehnt mit Begründung ab.
     */
    public function testLehntDieBestellungAbGleicheAntwortWieOhneTreffer(): void
    {
        $this->connection->update('tl_consho_shop', ['withdrawalDays' => 0], ['id' => self::SHOP]);

        $redirect = $this->post(['consho_withdrawal_orderNumber' => 'WEB-00073', 'consho_withdrawal_email' => 'kundin@example.org', 'consho_withdrawal_name' => 'E']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_withdrawal=done', $redirect->getResponse()->headers->get('Location'));
        self::assertSame(['0', 'WEB-00073'], array_map('strval', $this->connection->fetchNumeric('SELECT pid, orderReference FROM tl_consho_withdrawal') ?: []));
        self::assertSame([], $this->confirmed, 'keine Mail mit Bestelldaten');
        self::assertSame([[self::SHOP, 'kundin@example.org', 'de']], $this->unassigned);
    }

    /**
     * Zweites Review WA, B1: Nur der Weg ohne Bestellung hat eine
     * Mail-Grenze. Hieße es dann „nicht bestätigt", verrieten ein paar
     * Versuche, welche Paare treffen — also dieselbe Antwort, ob die Mail
     * hinausging oder nicht (gestanden wird das im Log und im Backend).
     */
    public function testOhneMailDieselbeAntwortWieMitMail(): void
    {
        $this->mailWorks = false;

        foreach ([['WEB-00073', 'kundin@example.org'], ['WEB-99999', 'jemand@example.org']] as [$number, $email]) {
            $redirect = $this->post(['consho_withdrawal_orderNumber' => $number, 'consho_withdrawal_email' => $email, 'consho_withdrawal_name' => 'E']);

            self::assertInstanceOf(RedirectResponseException::class, $redirect);
            self::assertSame(self::URL.'?consho_withdrawal=done', $redirect->getResponse()->headers->get('Location'), $number);
        }

        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal WHERE confirmationSentAt IS NULL'), 'gespeichert, als unbestätigt erkennbar');
        self::assertNull($this->render('?consho_withdrawal=doneUnconfirmed')['done'], 'die alte Meldung gibt es nicht mehr');
    }

    public function testDieBestaetigungNachDemUmleiten(): void
    {
        $data = $this->render('?consho_withdrawal=done');

        self::assertSame('done', $data['done']);
        self::assertArrayNotHasKey('number', $data);
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

            return $this->mailWorks;
        });
        $mail->method('withdrawalReceivedUnassigned')->willReturnCallback(function (int $shopId, string $email, string $language): bool {
            $this->unassigned[] = [$shopId, $email, $language];

            return $this->mailWorks;
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
