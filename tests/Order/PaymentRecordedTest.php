<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderEvent;
use Webwerkwien\ContaoConshoBundle\Order\OrderStatusAdvancer;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquencyRun;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Die Folgen einer erfassten Zahlung (D-1, Spec D 5.4, Entscheidungen Z1, Z3, A2).
 *
 * Der Saldo kommt aus dem echten Leser gegen SQLite; nur die Sperre
 * (FOR UPDATE, das SQLite nicht kennt) und die Nebenwirkungen nach außen
 * sind Doubles.
 */
final class PaymentRecordedTest extends TestCase
{
    private const ORDER = 7;

    private const ONLINE = 4;

    private const PREPAYMENT = 5;

    private Connection $connection;

    /** @var list<int> */
    private array $issued = [];

    /** @var list<int> */
    private array $archived = [];

    /** @var list<OrderEvent> */
    private array $advanced = [];

    /** @var list<array{int, int|null, string, bool}> */
    private array $mails = [];

    private string|null $orderPage = 'https://shop.test/bestellung?consho_order=abc';

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');

        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'orderNumber' => 'WEB-00080', 'grandTotal' => '40', 'paymentMethod' => self::ONLINE]);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'quantity' => '1']);
    }

    public function testDieVolleOnlineZahlungStelltDieRechnungAusUndSchicktDieMail(): void
    {
        $this->pay('40');

        $this->recorded()->handle(self::ORDER, true, 100);

        self::assertSame([self::ORDER], $this->issued);
        self::assertSame([55], $this->archived);
        self::assertSame([OrderEvent::PaymentSettled], $this->advanced);
        self::assertSame([[self::ORDER, 55, (string) $this->orderPage, true]], $this->mails);
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT paymentSettledAt FROM tl_consho_order'));
        self::assertSame(100, (int) $this->connection->fetchOne('SELECT lockedAt FROM tl_consho_payment'));
    }

    public function testEineTeilzahlungHatKeineFolgenUndBleibtBearbeitbar(): void
    {
        $this->pay('10');

        $this->recorded()->handle(self::ORDER, false, 100);

        self::assertSame([], $this->issued);
        self::assertSame([], $this->mails);
        self::assertNull($this->connection->fetchOne('SELECT lockedAt FROM tl_consho_payment'));
    }

    public function testNachDerErledigungFeuertNichtsNochEinmal(): void
    {
        $this->pay('40');
        $this->recorded()->handle(self::ORDER, true, 100);

        // Erstattung und neue Zahlung: kein zweites „Zahlung erhalten".
        $this->pay('-40');
        $this->pay('40');
        $this->recorded()->handle(self::ORDER, false, 200);

        self::assertCount(1, $this->mails);
        self::assertCount(1, $this->issued);
    }

    public function testBeiVorkasseGibtEsDieRechnungSchonUndDieMailKommtOhneAnhang(): void
    {
        $this->connection->update('tl_consho_order', ['paymentMethod' => self::PREPAYMENT], ['id' => self::ORDER]);
        $this->pay('40');

        $this->recorded()->handle(self::ORDER, false, 100);

        self::assertSame([], $this->issued);
        self::assertSame([[self::ORDER, null, (string) $this->orderPage, false]], $this->mails);
    }

    public function testEineStornierteBestellungSchicktKeinZahlungErhalten(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 50], ['id' => self::ORDER]);

        $this->recorded()->handle(self::ORDER, false, 100);

        self::assertSame([], $this->mails);
        self::assertSame([], $this->advanced);
    }

    public function testOhneBestellseiteKeineMailAberDieRechnungEntsteht(): void
    {
        $this->orderPage = null;
        $this->pay('40');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(self::stringContains('WEB-00080'));

        $this->recorded($logger)->handle(self::ORDER, true, 100);

        self::assertSame([self::ORDER], $this->issued);
        self::assertSame([], $this->mails);
    }

    /**
     * Abo-5 (P35): Die Zahlung, die die Bestellung begleicht, weckt ein
     * ruhendes Abo sofort — eine Teilzahlung fragt gar nicht erst.
     */
    public function testDieBegleichendeZahlungFragtNachDenAbos(): void
    {
        $subscriptions = $this->createMock(SubscriptionDelinquencyRun::class);
        $subscriptions->method('afterPayment')->willReturnCallback(function (int $orderId, int $now): array {
            $this->asked[] = [$orderId, $now];

            return [];
        });

        $this->pay('10');
        $this->recorded(subscriptions: $subscriptions)->handle(self::ORDER, false, 100);
        self::assertSame([], $this->asked, 'Teilzahlung');

        $this->pay('30');
        $this->recorded(subscriptions: $subscriptions)->handle(self::ORDER, false, 200);
        self::assertSame([[self::ORDER, 200]], $this->asked);
    }

    /**
     * Abnahme Abo-5: „wieder aktiv" kam eine Sekunde vor „Zahlung erhalten".
     * Erst die Zahlung, dann das Abo — und ohne Bestellseite trotzdem das Abo.
     */
    public function testErstDieZahlungsmailDannDasAbo(): void
    {
        $subscriptions = $this->createMock(SubscriptionDelinquencyRun::class);
        $subscriptions->method('afterPayment')->willReturnCallback(function (int $orderId): array {
            $this->asked[] = [$orderId, \count($this->mails)];

            return [];
        });

        $this->pay('40');
        $this->recorded(subscriptions: $subscriptions)->handle(self::ORDER, false, 100);

        self::assertSame([[self::ORDER, 1]], $this->asked, 'die Zahlungsmail war schon draußen');
    }

    public function testOhneBestellseiteWerdenDieAbosTrotzdemGefragt(): void
    {
        $this->orderPage = null;
        $subscriptions = $this->createMock(SubscriptionDelinquencyRun::class);
        $subscriptions->expects(self::once())->method('afterPayment')->willReturn([]);

        $this->pay('40');
        $this->recorded(subscriptions: $subscriptions)->handle(self::ORDER, false, 100);
    }

    public function testEinFehlerBeiDenAbosHaeltDieZahlungNichtAuf(): void
    {
        $subscriptions = $this->createMock(SubscriptionDelinquencyRun::class);
        $subscriptions->method('afterPayment')->willThrowException(new \RuntimeException('db gone'));

        $this->pay('40');
        $this->recorded(subscriptions: $subscriptions)->handle(self::ORDER, true, 100);

        self::assertCount(1, $this->mails, '„Zahlung erhalten" geht trotzdem');
    }

    /** @var list<array{int, int}> order and time of each afterPayment() */
    private array $asked = [];

    private function pay(string $amount): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => $amount]);
    }

    private function recorded(LoggerInterface|null $logger = null, SubscriptionDelinquencyRun|null $subscriptions = null): PaymentRecorded
    {
        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturnCallback(function (int $id): array|null {
            $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]);

            return false === $row ? null : array_map(static fn ($v): string => (string) $v, $row);
        });

        $methods = $this->createStub(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturnCallback(static fn (int $id): PaymentMethod => match ($id) {
            self::ONLINE => new PaymentMethod(self::ONLINE, 'test', 'Testzahlung', []),
            default => new PaymentMethod(self::PREPAYMENT, 'prepayment', 'Vorkasse', []),
        });

        $test = new TestPaymentHandler($this->createStub(UrlGeneratorInterface::class));
        $prepayment = new PrepaymentHandler();
        $handlers = new PaymentHandlers(new ServiceLocator(['test' => static fn () => $test, 'prepayment' => static fn () => $prepayment]), '1');

        $documents = $this->createStub(DocumentIssuerInterface::class);
        $documents->method('hasDocument')->willReturnCallback(fn (int $orderId, DocumentType $type): bool => [] !== $this->issued);
        $documents->method('issueOnce')->willReturnCallback(function (int $orderId): int {
            $this->issued[] = $orderId;

            return 55;
        });

        $archiver = $this->createStub(DocumentArchiverInterface::class);
        $archiver->method('archive')->willReturnCallback(function (int $id): void {
            $this->archived[] = $id;
        });

        $statuses = $this->createStub(OrderStatusAdvancer::class);
        $statuses->method('advance')->willReturnCallback(function (int $orderId, OrderEvent $event): null {
            $this->advanced[] = $event;

            return null;
        });

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturnCallback(fn (): string|null => $this->orderPage);

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('paymentReceived')->willReturnCallback(function (int $orderId, int|null $invoiceId, string $viewUrl, bool $copyOperator): bool {
            $this->mails[] = [$orderId, $invoiceId, $viewUrl, $copyOperator];

            return true;
        });

        return new PaymentRecorded(
            $this->connection,
            $orders,
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            $methods,
            $handlers,
            $documents,
            $archiver,
            $statuses,
            $links,
            $mail,
            $logger ?? $this->createStub(LoggerInterface::class),
            null,
            $subscriptions,
        );
    }
}
