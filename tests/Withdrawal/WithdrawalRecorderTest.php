<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
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

    /** @var list<array{shopId: int, email: string, language: string, withdrawal: array<string, mixed>}> */
    private array $unassignedMails = [];

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

    /**
     * WA, B1 (Michael, 2026-10-22): Das Fenster rechnet ab Versand, die Frist
     * ab Erhalt. Was danach eingeht, wird gespeichert, bestätigt und markiert
     * — nicht mehr abgelehnt. Bis dahin: Closed, nichts gespeichert.
     */
    public function testNachFensterendeGespeichertUndMarkiert(): void
    {
        $this->shipEverything(self::NOW - 40 * 86400);

        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E'), self::NOW);

        self::assertSame(WithdrawalOutcome::Recorded, $result->outcome);
        self::assertTrue($result->afterWindow);
        self::assertTrue($result->confirmed);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT afterWindow FROM tl_consho_withdrawal'));
    }

    public function testImFensterNichtMarkiert(): void
    {
        $this->shipEverything(self::NOW - 3 * 86400);

        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E'), self::NOW);

        self::assertFalse($result->afterWindow);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT afterWindow FROM tl_consho_withdrawal'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT shop FROM tl_consho_withdrawal'), 'der Shop der Bestellung');
        self::assertSame('WEB-00073', $this->connection->fetchOne('SELECT orderReference FROM tl_consho_withdrawal'));
    }

    /**
     * B1: Nur der zeitliche Schluss wird zur Markierung. Ohne Widerrufsfunktion
     * (Frist 0) bleibt es eine Ablehnung — da ist nichts abgelaufen.
     */
    public function testOhneWiderrufsfunktionWeiterAbgelehnt(): void
    {
        $this->connection->update('tl_consho_shop', ['withdrawalDays' => 0], ['id' => 1]);

        $result = $this->recorder()->record(new WithdrawalDeclaration(self::ORDER, WithdrawalSource::OrderPage, 'E'), self::NOW);

        self::assertSame(WithdrawalOutcome::Closed, $result->outcome);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    /**
     * WA, A1–A3: Passt keine Bestellung, wird trotzdem gespeichert — ohne
     * Bestellung, mit dem Shop, der eingetippten Bestellnummer und der
     * eingegebenen Adresse; bestätigt wird an diese Adresse.
     */
    public function testNichtZugeordnetWirdGespeichertUndAnDieEingegebeneAdresseBestaetigt(): void
    {
        $result = $this->recorder()->recordUnassigned(1, 'de', ' WEB-0007 ', ' jemand@example.net ', 'Erika Muster', '', 'bitte rasch', self::NOW);

        self::assertSame(WithdrawalOutcome::Recorded, $result->outcome);
        self::assertSame('W-00001', $result->number);
        self::assertTrue($result->confirmed);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_withdrawal');

        self::assertIsArray($row);
        self::assertSame(
            ['pid' => 0, 'shop' => 1, 'number' => 'W-00001', 'orderReference' => 'WEB-0007', 'email' => 'jemand@example.net', 'scope' => 'all', 'source' => 'form', 'state' => 'received'],
            ['pid' => (int) $row['pid'], 'shop' => (int) $row['shop'], 'number' => $row['number'], 'orderReference' => $row['orderReference'], 'email' => $row['email'], 'scope' => $row['scope'], 'source' => $row['source'], 'state' => $row['state']],
        );
        self::assertNotNull($row['confirmationSentAt']);

        self::assertSame([], $this->mails, 'keine Mail über eine Bestellung');
        self::assertSame(1, $this->unassignedMails[0]['shopId']);
        self::assertSame('jemand@example.net', $this->unassignedMails[0]['email']);
        self::assertSame('WEB-0007', $this->unassignedMails[0]['withdrawal']['orderReference']);
    }

    public function testNichtZugeordnetMitTeilUndGekuerztemText(): void
    {
        $this->recorder()->recordUnassigned(1, 'de', 'X', 'a@example.net', 'E', str_repeat('ä', WithdrawalRecorder::UNASSIGNED_TEXT_LIMIT + 50), '', self::NOW);

        $row = $this->connection->fetchAssociative('SELECT scope, part FROM tl_consho_withdrawal');

        self::assertIsArray($row);
        self::assertSame('part', $row['scope']);
        self::assertSame(WithdrawalRecorder::UNASSIGNED_TEXT_LIMIT, mb_strlen((string) $row['part']));
    }

    /**
     * A3: Ist die Obergrenze verbraucht, wird trotzdem gespeichert — nur die
     * Mail fällt aus, wie bei einem ausgefallenen Mailserver.
     */
    public function testUeberDerObergrenzeGespeichertAberNichtBestaetigt(): void
    {
        $limits = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
        $recorder = $this->recorder($limits);

        self::assertTrue($recorder->recordUnassigned(1, 'de', 'X', 'a@example.net', 'E', '', '', self::NOW)->confirmed);

        $second = $recorder->recordUnassigned(1, 'de', 'Y', 'b@example.net', 'E', '', '', self::NOW);

        self::assertSame(WithdrawalOutcome::Recorded, $second->outcome);
        self::assertFalse($second->confirmed);
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
        self::assertCount(1, $this->unassignedMails);
    }

    /**
     * A3: Je Adresse begrenzt, nicht für alle zusammen — sonst sperrte ein
     * Fremder mit seinen Versuchen die Bestätigung eines anderen.
     */
    public function testDieObergrenzeJeAdresseZaehltJedeAdresseFuerSich(): void
    {
        $limits = new RateLimiterFactory(['id' => 'test', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
        $recorder = $this->recorder(perAddress: $limits);

        self::assertTrue($recorder->recordUnassigned(1, 'de', 'X', 'a@example.net', 'E', '', '', self::NOW)->confirmed);
        self::assertFalse($recorder->recordUnassigned(1, 'de', 'X', 'A@Example.net', 'E', '', '', self::NOW)->confirmed, 'dieselbe Adresse, andere Schreibung');
        self::assertTrue($recorder->recordUnassigned(1, 'de', 'X', 'b@example.net', 'E', '', '', self::NOW)->confirmed);
    }

    private function shipEverything(int $at): void
    {
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => self::ORDER, 'shippedAt' => $at, 'reportedAt' => $at]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => '2']);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 2, 'quantity' => '1']);
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

    /**
     * Review WA, K2 und K3: eine Flut auf EINE Adresse zehrt das Kontingent
     * des Shops nicht auf, und die Grenze ist eine Warnung, kein Fehler.
     */
    public function testDieAdresseZuerstUndDieGrenzeAlsWarnung(): void
    {
        $perShop = new RateLimiterFactory(['id' => 'shop', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '1 hour'], new InMemoryStorage());
        $perAddress = new RateLimiterFactory(['id' => 'address', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 hour'], new InMemoryStorage());
        $logger = new class extends AbstractLogger {
            /** @var list<string> */
            public array $levels = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->levels[] = (string) $level;
            }
        };
        $recorder = $this->recorder($perShop, $perAddress, $logger);

        self::assertTrue($recorder->recordUnassigned(1, 'de', 'X', 'a@example.net', 'E', '', '', self::NOW)->confirmed);
        self::assertFalse($recorder->recordUnassigned(1, 'de', 'X', 'a@example.net', 'E', '', '', self::NOW)->confirmed);
        self::assertTrue($recorder->recordUnassigned(1, 'de', 'X', 'b@example.net', 'E', '', '', self::NOW)->confirmed, 'die zweite Mail des Shops ist noch frei');
        self::assertSame(['warning'], $logger->levels);

        $this->mailWorks = false;
        $recorder->recordUnassigned(1, 'de', 'X', 'c@example.net', 'E', '', '', self::NOW);

        self::assertSame(['warning', 'warning'], $logger->levels, 'Shop-Grenze erreicht');

        $this->recorder(logger: $logger)->recordUnassigned(1, 'de', 'X', 'd@example.net', 'E', '', '', self::NOW);

        self::assertSame(['warning', 'warning', 'error'], $logger->levels, 'die Mail scheiterte wirklich');
    }

    private function recorder(RateLimiterFactory|null $perShop = null, RateLimiterFactory|null $perAddress = null, LoggerInterface|null $logger = null): WithdrawalRecorder
    {
        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('withdrawalReceived')->willReturnCallback(function (int $orderId, array $withdrawal, string $viewUrl): bool {
            $this->mails[] = ['orderId' => $orderId, 'withdrawal' => $withdrawal, 'viewUrl' => $viewUrl];

            return $this->mailWorks;
        });
        $mail->method('withdrawalReceivedUnassigned')->willReturnCallback(function (int $shopId, string $email, string $language, array $withdrawal): bool {
            $this->unassignedMails[] = ['shopId' => $shopId, 'email' => $email, 'language' => $language, 'withdrawal' => $withdrawal];

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
            logger: $logger ?? new NullLogger(),
            perShop: $perShop,
            perAddress: $perAddress,
        );
    }
}
