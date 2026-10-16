<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Withdrawal\DbalWithdrawalRepository;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalDeclaration;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalOutcome;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSource;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * Widerruf speichern, dann bestätigen (Spec D 6.1, Schritt 4). Echte Salden
 * und echtes Fenster auf SQLite; die Mail ist ein Spion, ihr Inhalt steht in
 * OrderEventMailTest.
 *
 * Schema wie die DCA (Prüfungsnotiz, Fall 31): tl_consho_withdrawal mit
 * (`pid`, `number`) eindeutig, `part` und `message` NULL-fähig.
 */
final class WithdrawalRecorderTest extends TestCase
{
    private const ORDER = 7;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    /** @var list<array{orderId: int, withdrawal: array<string, mixed>, viewUrl: string}> */
    private array $mails = [];

    private bool $mailWorks = true;

    private string|null $link = 'https://shop.test/bestellung?consho_order=abc';

    protected function setUp(): void
    {
        // K2: the remainder is read under the order's lock; SQLite drops the clause.
        $this->connection = SqliteWithoutRowLocks::connection();
        SqliteWithoutRowLocks::$locked = [];
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_order_status');
        // From the DCA (acceptance WG-7, Prüfungsnotiz Punkt 56), not a copy.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');
        WithdrawalSchema::create($this->connection);

        $this->connection->insert('tl_consho_shop', ['id' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => 1, 'orderNumber' => 'WEB-00073', 'email' => 'kundin@example.org', 'grandTotal' => '30.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Wanderstock', 'quantity' => '2.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'productName' => 'Kräutersalz', 'quantity' => '1.0000']);
        $this->connection->insert('tl_consho_order_item', ['id' => 3, 'pid' => self::ORDER, 'productName' => 'Versand', 'quantity' => '1.0000', 'lineType' => 'shipping']);
    }

    public function testGanzeBestellungWirdGespeichertUndBestaetigt(): void
    {
        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'Erika Muster'), self::NOW);

        self::assertSame(WithdrawalOutcome::Recorded, $result->outcome);
        self::assertSame('WEB-00073-W1', $result->number);
        self::assertTrue($result->confirmed);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal');

        self::assertIsArray($row);
        self::assertSame(self::NOW, (int) $row['receivedAt']);
        self::assertSame('all', $row['scope']);
        self::assertSame('orderPage', $row['source']);
        self::assertSame('kundin@example.org', $row['email'], 'die Adresse der Bestellung');
        self::assertNotNull($row['confirmationSentAt']);
        self::assertNull($row['part']);

        self::assertSame(self::NOW, $this->mails[0]['withdrawal']['receivedAt']);
        self::assertSame('https://shop.test/bestellung?consho_order=abc', $this->mails[0]['viewUrl']);
    }

    public function testPositionenMitMengen(): void
    {
        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'Erika Muster', [1 => '1']), self::NOW);

        self::assertSame(WithdrawalOutcome::Recorded, $result->outcome);
        self::assertSame('items', $this->connection->fetchOne('SELECT scope FROM tl_consho_withdrawal'));
        self::assertSame([['quantity' => '1', 'name' => 'Wanderstock']], $this->mails[0]['withdrawal']['items']);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal_item'));
    }

    /**
     * Abo-8, P77: Eine Abo-Zeile ist nur ganz widerrufbar. Das Formular bietet
     * sie nur ganz an; ein Teil kommt nur aus einem veränderten Formular.
     */
    public function testEineAboZeileNurGanz(): void
    {
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 3], ['id' => 1]);

        self::assertSame(WithdrawalOutcome::InvalidItems, $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [1 => '1']), self::NOW)->outcome);
        self::assertSame(WithdrawalOutcome::Recorded, $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [1 => '2']), self::NOW)->outcome);
    }

    public function testTeilAlsFreitext(): void
    {
        $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::Form, 'Erika Muster', [], '  nur das Salz  '), self::NOW);

        $row = $this->connection->fetchAssociative('SELECT scope, part, source FROM tl_consho_withdrawal');

        self::assertSame(['scope' => 'part', 'part' => 'nur das Salz', 'source' => 'form'], $row);
    }

    public function testZweiterWiderrufBekommtDieNaechsteNummer(): void
    {
        $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [1 => '1']), self::NOW);
        $second = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [1 => '1']), self::NOW);

        self::assertSame('WEB-00073-W2', $second->number);
    }

    /**
     * Die Nummer „-Wn+1" ist schon vergeben (ein gleichzeitiger Widerruf):
     * der eindeutige Schlüssel weist ab, es gilt die nächste.
     */
    public function testBelegteNummerWirdUebersprungen(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => self::ORDER, 'number' => 'WEB-00073-W2']);

        self::assertSame('WEB-00073-W3', $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E'), self::NOW)->number);
    }

    /**
     * Bestellnummern sind nur je Shop eindeutig: dieselbe Nummer in einem
     * anderen Shop darf den Widerruf nicht blockieren.
     */
    public function testGleicheBestellnummerEinesAnderenShops(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 99, 'number' => 'WEB-00073-W1']);

        self::assertSame('WEB-00073-W1', $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E'), self::NOW)->number);
    }

    /**
     * Nachlese K2: Zwei gleichzeitige Widerrufe derselben Restmenge gingen
     * beide durch. Die Restmenge wird jetzt unter der Sperre der Bestellung
     * gelesen — SQLite kennt keine Sperre, also belegt der Test, DASS sie
     * genommen wird, und zwar bevor geprüft wird.
     */
    public function testRestmengeUnterDerSperreDerBestellung(): void
    {
        $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [1 => '1']), self::NOW);

        self::assertSame(['SELECT id FROM tl_consho_order WHERE id = ? FOR UPDATE'], SqliteWithoutRowLocks::takeLocked());
    }

    public function testAbgewiesenOhneOffeneTransaktion(): void
    {
        $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [99 => '1']), self::NOW);

        self::assertFalse($this->connection->isTransactionActive());
    }

    public function testMehrAlsUebrigIstUngueltig(): void
    {
        $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [1 => '2']), self::NOW);

        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', [1 => '1']), self::NOW);

        self::assertSame(WithdrawalOutcome::InvalidItems, $result->outcome);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    /**
     * @return iterable<string, array{array<int, string>}>
     */
    public static function tamperedItems(): iterable
    {
        yield 'Versandzeile' => [[3 => '1']];
        yield 'fremde Position' => [[99 => '1']];
        yield 'null' => [[1 => '0']];
        yield 'negativ' => [[1 => '-1']];
        yield 'kein Betrag' => [[1 => 'x']];
        yield 'mehr als bestellt' => [[2 => '1.5']];
    }

    /**
     * @param array<int, string> $items
     *
     * @dataProvider tamperedItems
     */
    public function testManipuliertePositionenWerdenAbgewiesen(array $items): void
    {
        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E', $items), self::NOW);

        self::assertSame(WithdrawalOutcome::InvalidItems, $result->outcome);
        self::assertSame([], $this->mails);
    }

    public function testNachFristendeGeschlossen(): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => self::ORDER, 'shippedAt' => self::NOW - 40 * 86400, 'reportedAt' => self::NOW - 40 * 86400]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '2']);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 2, 'quantity' => '1']);

        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E'), self::NOW);

        self::assertSame(WithdrawalOutcome::Closed, $result->outcome);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    public function testUnbekannteBestellung(): void
    {
        self::assertSame(WithdrawalOutcome::UnknownOrder, $this->recorder()->record(new WithdrawalDeclaration(99, WithdrawalSource::Form, 'E'), self::NOW)->outcome);
    }

    /**
     * Der Widerruf gilt mit dem Eingang — ein ausgefallener Mailserver ändert
     * nichts daran, nur `confirmationSentAt` bleibt leer.
     */
    public function testOhneMailBleibtDerWiderrufStehen(): void
    {
        $this->mailWorks = false;

        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E'), self::NOW);

        self::assertSame(WithdrawalOutcome::Recorded, $result->outcome);
        self::assertFalse($result->confirmed);
        self::assertNull($this->connection->fetchOne('SELECT confirmationSentAt FROM tl_consho_withdrawal'));
    }

    public function testOhneBestellseiteGehtDieBestaetigungTrotzdem(): void
    {
        $this->link = null;

        self::assertTrue($this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::Form, 'E'), self::NOW)->confirmed);
        self::assertSame('', $this->mails[0]['viewUrl']);
    }

    private function recorder(): WithdrawalRecorder
    {
        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('withdrawalReceived')->willReturnCallback(function (int $orderId, array $withdrawal, string $viewUrl): bool {
            $this->mails[] = ['orderId' => $orderId, 'withdrawal' => $withdrawal, 'viewUrl' => $viewUrl];

            return $this->mailWorks;
        });

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturnCallback(fn (): string|null => $this->link);

        return new WithdrawalRecorder(
            $this->connection,
            new WithdrawalWindowReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator())),
            new DbalWithdrawalRepository($this->connection),
            $mail,
            $links,
        );
    }
}
