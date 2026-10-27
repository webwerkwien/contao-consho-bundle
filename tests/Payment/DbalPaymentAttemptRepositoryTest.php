<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\DbalPaymentAttemptRepository;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The queries behind 7.4 against SQLite. Not lock(): SQLite has no FOR
 * UPDATE; the lock is checked on MySQL/MariaDB by sending the same
 * notification twice, and the unique key holds even without it.
 */
class DbalPaymentAttemptRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // From the DCA, not by hand: a hand-written table lacked `charge` (Abo-4b).
        DcaSchema::create($this->connection, 'tl_consho_payment_attempt');
        // From the DCA (WG-7), not a copy.
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
    }

    /**
     * Abo-4b, P94: a debit attempt by the subscription cron stays recognisable
     * as one — the message for it is "subscription invoice", not "payment
     * received".
     */
    public function testEinAbbuchungsversuchBleibtErkennbar(): void
    {
        $charge = $this->repository()->open(4, new PaymentMethod(7, 'card', 'Karte', []), '1.2000', 'EUR', str_repeat('c', 64), 100, true);
        $browser = $this->repository()->open(4, new PaymentMethod(7, 'card', 'Karte', []), '1.2000', 'EUR', str_repeat('d', 64), 100);

        self::assertTrue($this->repository()->findByHash(str_repeat('c', 64))?->charge);
        self::assertFalse($this->repository()->findByHash(str_repeat('d', 64))?->charge);
        self::assertSame([$charge, $browser], [$charge, $this->repository()->latestFor(4)?->id]);
    }

    public function testEinGeoeffneterVersuchWirdUeberDenHashGefunden(): void
    {
        $id = $this->repository()->open(4, new PaymentMethod(7, 'test', 'Test', []), '13.9000', 'EUR', str_repeat('a', 64), 100);

        $attempt = $this->repository()->findByHash(str_repeat('a', 64));

        self::assertSame($id, $attempt?->id);
        self::assertSame(4, $attempt->orderId);
        self::assertSame('test', $attempt->methodType);
        self::assertSame(AttemptState::Open, $attempt->state);
        self::assertNull($attempt->returned);
        self::assertSame(100, $attempt->createdAt, 'der Verfall rechnet ab hier');
        self::assertNull($this->repository()->findByHash(str_repeat('b', 64)));
    }

    public function testDerNeuesteVersuchEinerBestellung(): void
    {
        $this->repository()->open(4, new PaymentMethod(2, 'prepayment', 'Vorkasse', []), '10', 'EUR', str_repeat('a', 64), 100);
        $second = $this->repository()->open(4, new PaymentMethod(7, 'test', 'Test', []), '10', 'EUR', str_repeat('b', 64), 101);
        $this->repository()->open(5, new PaymentMethod(7, 'test', 'Test', []), '10', 'EUR', str_repeat('c', 64), 102);

        self::assertSame($second, $this->repository()->latestFor(4)?->id);
        self::assertNull($this->repository()->latestFor(6));
    }

    public function testZustandUndReferenz(): void
    {
        $id = $this->repository()->open(4, new PaymentMethod(7, 'test', 'Test', []), '10', 'EUR', str_repeat('a', 64), 100);

        $this->repository()->setState($id, AttemptState::Succeeded, 200, 'T-1');

        $attempt = $this->repository()->findByHash(str_repeat('a', 64));
        self::assertSame(AttemptState::Succeeded, $attempt?->state);
        self::assertSame('T-1', $attempt->providerReference);

        // Without a reference the old one stays.
        $this->repository()->setState($id, AttemptState::Failed, 201);
        self::assertSame('T-1', $this->repository()->findByHash(str_repeat('a', 64))?->providerReference);
    }

    public function testEineRueckkehrSetztAbbruchNurAufOffeneVersucheUndErfolgNie(): void
    {
        $open = $this->repository()->open(4, new PaymentMethod(7, 'test', 'Test', []), '10', 'EUR', str_repeat('a', 64), 100);
        $paid = $this->repository()->open(4, new PaymentMethod(7, 'test', 'Test', []), '10', 'EUR', str_repeat('b', 64), 100);
        $other = $this->repository()->open(4, new PaymentMethod(7, 'test', 'Test', []), '10', 'EUR', str_repeat('c', 64), 100);
        $this->repository()->setState($paid, AttemptState::Succeeded, 150);

        $this->repository()->recordReturn($open, AttemptState::Cancelled, 200);
        $this->repository()->recordReturn($paid, AttemptState::Failed, 200);
        $this->repository()->recordReturn($other, AttemptState::Succeeded, 200);

        $cancelled = $this->repository()->findByHash(str_repeat('a', 64));
        self::assertSame(AttemptState::Cancelled, $cancelled?->state);
        self::assertSame(AttemptState::Cancelled, $cancelled->returned);

        // The notification was faster: paid stays paid.
        $stillPaid = $this->repository()->findByHash(str_repeat('b', 64));
        self::assertSame(AttemptState::Succeeded, $stillPaid?->state);
        self::assertSame(AttemptState::Failed, $stillPaid->returned);

        // A "successful" return is recorded, the state stays open.
        $returned = $this->repository()->findByHash(str_repeat('c', 64));
        self::assertSame(AttemptState::Open, $returned?->state);
        self::assertSame(AttemptState::Succeeded, $returned->returned);
    }

    public function testDerAufraeumerFindetNurAlteOffeneUndBrichtNurOffeneAb(): void
    {
        $method = new PaymentMethod(7, 'test', 'Test', []);
        $old = $this->repository()->open(4, $method, '10', 'EUR', str_repeat('a', 64), 100);
        $young = $this->repository()->open(4, $method, '10', 'EUR', str_repeat('b', 64), 5000);
        $paid = $this->repository()->open(4, $method, '10', 'EUR', str_repeat('c', 64), 100);
        $this->repository()->setState($paid, AttemptState::Succeeded, 200);

        self::assertSame([$old], array_map(static fn ($a): int => $a->id, $this->repository()->openBefore(1000)));
        self::assertNotSame($young, $old);

        self::assertTrue($this->repository()->cancelIfOpen($old, 2000));
        self::assertFalse($this->repository()->cancelIfOpen($old, 2001), 'schon abgebrochen');
        self::assertFalse($this->repository()->cancelIfOpen($paid, 2001), 'bezahlt bleibt bezahlt');
        self::assertSame(AttemptState::Cancelled, $this->repository()->findByHash(str_repeat('a', 64))?->state);
        self::assertSame(AttemptState::Succeeded, $this->repository()->findByHash(str_repeat('c', 64))?->state);
    }

    public function testEineZahlungJeVersuchVerweigertDieDatenbank(): void
    {
        $id = $this->repository()->open(4, new PaymentMethod(7, 'test', 'Test', []), '13.9000', 'EUR', str_repeat('a', 64), 100);
        $attempt = $this->repository()->findByHash(str_repeat('a', 64));
        self::assertNotNull($attempt);

        $this->repository()->recordPayment($attempt, '13.9000', 'T-1', 'Test-Anbieter', 300);

        $row = $this->connection->fetchAssociative('SELECT pid, receivedAt, amount, method, reference, attempt FROM tl_consho_payment');
        self::assertIsArray($row);
        self::assertSame([4, 300, $id], [(int) $row['pid'], (int) $row['receivedAt'], (int) $row['attempt']]);
        self::assertSame(['Test-Anbieter', 'T-1'], [$row['method'], $row['reference']]);
        self::assertEqualsWithDelta(13.9, (float) $row['amount'], 0.00001);

        // Manual entries without an attempt may be any number.
        $this->connection->insert('tl_consho_payment', ['pid' => 4, 'amount' => '1']);
        $this->connection->insert('tl_consho_payment', ['pid' => 4, 'amount' => '1']);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->repository()->recordPayment($attempt, '13.9000', 'T-1', 'Test-Anbieter', 301);
    }

    private function repository(): DbalPaymentAttemptRepository
    {
        return new DbalPaymentAttemptRepository($this->connection);
    }
}
