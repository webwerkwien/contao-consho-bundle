<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlers;
use Webwerkwien\ContaoConshoBundle\Digital\Entitlement;
use Webwerkwien\ContaoConshoBundle\Digital\EntitlementRevocations;
use Webwerkwien\ContaoConshoBundle\Digital\RevocableDeliveryHandlerInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\EntitlementRevokedListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Nachlese E6: A delivery module with `revoke()` learns which entitlements
 * were blocked — from the withdrawal as well as from the tick in the
 * backend.
 */
final class EntitlementRevocationsTest extends TestCase
{
    private Connection $connection;

    /** @var list<int> */
    private array $told = [];

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_entitlement');

        $this->connection->insert('tl_consho_entitlement', ['id' => 1, 'pid' => 7, 'deliveryType' => 'account', 'revoked' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 2, 'pid' => 7, 'deliveryType' => 'file', 'revoked' => 1]);
        $this->connection->insert('tl_consho_entitlement', ['id' => 3, 'pid' => 7, 'deliveryType' => 'account', 'revoked' => 0]);
    }

    public function testNurModuleMitRevokeUndNurGesperrte(): void
    {
        $revocations = $this->revocations();
        $revocations->revoked([1, 2, 3]);
        $revocations->tellPending();

        self::assertSame([1], $this->told, 'die Datei kennt kein revoke(), Nummer 3 ist nicht gesperrt');
    }

    public function testEinFehlerImModulWirdProtokolliert(): void
    {
        $revocations = $this->revocations(fails: true);
        $revocations->revoked([1]);
        $revocations->tellPending();

        self::assertSame([], $this->told);
        self::assertStringContainsString('entitlement 1', $this->logged[0] ?? '');
    }

    public function testDasHaekchenImBackendErreichtDasModul(): void
    {
        $revocations = $this->revocations();
        $listener = new EntitlementRevokedListener($revocations);

        // Number 3 is blocked: not yet on saving, but after the write.
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 3 : null);
        $dc->method('getCurrentRecord')->willReturn(['id' => 3, 'revoked' => '']);

        self::assertSame('1', $listener->remember('1', $dc));
        $this->connection->update('tl_consho_entitlement', ['revoked' => 1], ['id' => 3]);
        $listener->tell($dc);
        $listener->tell($dc);
        $revocations->tellPending();
        $revocations->tellPending();

        self::assertSame([3], $this->told, 'einmal');
    }

    public function testSchonGesperrtMeldetDasBackendNichtNochmal(): void
    {
        $listener = new EntitlementRevokedListener($this->revocations());

        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 1 : null);
        $dc->method('getCurrentRecord')->willReturn(['id' => 1, 'revoked' => '1']);

        $listener->remember('1', $dc);
        $listener->tell($dc);

        self::assertSame([], $this->told);
    }

    /**
     * Nachlese: only after the commit. While a transaction is running, the
     * notification waits; a block that is rolled back (revoked = 0 again)
     * never reports it.
     */
    public function testErstNachDemCommitUndNieNachEinemRollback(): void
    {
        $revocations = $this->revocations();

        $this->connection->beginTransaction();
        $this->connection->update('tl_consho_entitlement', ['revoked' => 1], ['id' => 3]);
        $revocations->revoked([1, 3]);
        $revocations->tellPending();
        self::assertSame([], $this->told, 'noch in der Transaktion');
        $this->connection->rollBack();

        $revocations->tellPending();

        self::assertSame([1], $this->told, 'Nummer 3 wurde zurückgerollt');
    }

    private function revocations(bool $fails = false): EntitlementRevocations
    {
        $account = new class(function (int $id) use ($fails): void {
            if ($fails) {
                throw new \RuntimeException('Server weg');
            }

            $this->told[] = $id;
        }) implements RevocableDeliveryHandlerInterface {
            public function __construct(private readonly \Closure $tell)
            {
            }

            public static function getType(): string
            {
                return 'account';
            }

            public function release(Entitlement $entitlement): bool
            {
                return true;
            }

            public function revoke(Entitlement $entitlement): void
            {
                ($this->tell)($entitlement->id);
            }
        };

        $file = new class implements DeliveryHandlerInterface {
            public static function getType(): string
            {
                return 'file';
            }

            public function release(Entitlement $entitlement): bool
            {
                return true;
            }
        };

        $logger = new class(function (string $line): void { $this->logged[] = $line; }) extends AbstractLogger {
            public function __construct(private readonly \Closure $write)
            {
            }

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                ($this->write)((string) $message);
            }
        };

        return new EntitlementRevocations(
            $this->connection,
            new DeliveryHandlers(new ServiceLocator(['account' => static fn () => $account, 'file' => static fn () => $file])),
            $logger,
        );
    }
}
