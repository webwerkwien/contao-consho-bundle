<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Address\VatIdValidatorInterface;
use Webwerkwien\ContaoConshoBundle\Customer\MemberVatId;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The VIES result on the member: twelve months, the same day for reverse
 * charge, only for the same VAT ID and requester, "unavailable" never.
 */
final class MemberVatIdTest extends TestCase
{
    private const NOW = 1_793_000_000;

    private Connection $connection;

    /** @var list<array{string, string|null}> */
    private array $queries = [];

    private VatIdStatus $answer = VatIdStatus::Valid;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_member');
        $this->connection->insert('tl_member', ['id' => 5, 'username' => 'kunde', 'consho_vatId' => 'de 123-456 789']);
    }

    public function testOhneErgebnisWirdGefragtUndGespeichert(): void
    {
        $result = $this->service()->ensure(5, 'ATU11111111', false, self::NOW);

        self::assertSame(VatIdStatus::Valid, $result?->status);
        self::assertSame([['DE123456789', 'ATU11111111']], $this->queries);
        self::assertSame(
            ['consho_vatIdStatus' => 'valid', 'consho_vatIdCheckedAt' => self::NOW, 'consho_vatIdRequestIdentifier' => 'WAPIAAAA', 'consho_vatIdRequester' => 'ATU11111111'],
            $this->stored(),
        );
    }

    public function testErgebnisGiltZwoelfMonate(): void
    {
        $this->store('valid', strtotime('-11 months', self::NOW), 'ATU11111111');

        $result = $this->service()->ensure(5, 'ATU11111111', false, self::NOW);

        self::assertSame(VatIdStatus::Valid, $result?->status);
        self::assertSame([], $this->queries);
    }

    public function testNachZwoelfMonatenNeu(): void
    {
        $this->store('valid', strtotime('-13 months', self::NOW), 'ATU11111111');

        $this->service()->ensure(5, 'ATU11111111', false, self::NOW);

        self::assertSame(1, $this->queryCount());
        self::assertSame(self::NOW, $this->stored()['consho_vatIdCheckedAt']);
    }

    public function testReverseChargeVerlangtDenGleichenTag(): void
    {
        $this->store('valid', self::NOW - 86_400, 'ATU11111111');

        $this->service()->ensure(5, 'ATU11111111', true, self::NOW);
        self::assertSame(1, $this->queryCount());

        // And the fresh one holds for the rest of the day.
        $this->service()->ensure(5, 'ATU11111111', true, self::NOW + 60);
        self::assertSame(1, $this->queryCount());
    }

    public function testUngueltigGiltAuchAlsErgebnis(): void
    {
        $this->store('invalid', self::NOW - 3600, 'ATU11111111');

        $result = $this->service()->ensure(5, 'ATU11111111', false, self::NOW);

        self::assertSame(VatIdStatus::Invalid, $result?->status);
        self::assertSame([], $this->queries);
    }

    public function testEinZeitpunktInDerZukunftGiltNicht(): void
    {
        $this->store('valid', self::NOW + 60, 'ATU11111111');

        $this->service()->ensure(5, 'ATU11111111', true, self::NOW);

        self::assertSame(1, $this->queryCount());
    }

    public function testNichtErreichbarGiltNie(): void
    {
        $this->store('unavailable', self::NOW - 60, 'ATU11111111');

        $this->service()->ensure(5, 'ATU11111111', false, self::NOW);

        self::assertSame(1, $this->queryCount());
    }

    public function testAndereAnfragendeUidFragtNeu(): void
    {
        $this->store('valid', self::NOW - 60, 'ATU11111111');

        $this->service()->ensure(5, 'ATU22222222', false, self::NOW);

        self::assertSame([['DE123456789', 'ATU22222222']], $this->queries);
        self::assertSame('ATU22222222', $this->stored()['consho_vatIdRequester']);
    }

    /**
     * The result belongs to the VAT ID it was asked for: a member who
     * changes the ID gets a new check, not the verdict of the old one.
     */
    public function testGeaenderteUidFragtNeu(): void
    {
        $this->store('valid', self::NOW - 60, 'ATU11111111');
        $this->connection->update('tl_member', ['consho_vatId' => 'DE987654321'], ['id' => 5]);
        $this->answer = VatIdStatus::Invalid;

        $result = $this->service()->ensure(5, 'ATU11111111', false, self::NOW);

        self::assertSame(VatIdStatus::Invalid, $result?->status);
        self::assertSame([['DE987654321', 'ATU11111111']], $this->queries);
        self::assertSame('DE987654321', $this->connection->fetchOne('SELECT consho_vatIdChecked FROM tl_member WHERE id = 5'));
    }

    public function testOhneShopUidEinfacheAbfrage(): void
    {
        $this->service()->ensure(5, '', false, self::NOW);

        self::assertSame([['DE123456789', null]], $this->queries);
    }

    public function testOhneUidWirdDasErgebnisGeleert(): void
    {
        $this->store('valid', self::NOW - 60, 'ATU11111111');
        $this->connection->update('tl_member', ['consho_vatId' => ''], ['id' => 5]);

        self::assertNull($this->service()->ensure(5, 'ATU11111111', false, self::NOW));
        self::assertSame([], $this->queries);
        self::assertSame(
            ['consho_vatIdStatus' => '', 'consho_vatIdCheckedAt' => null, 'consho_vatIdRequestIdentifier' => '', 'consho_vatIdRequester' => ''],
            $this->stored(),
        );
    }

    public function testRecordNurFuerDieUidDesMitglieds(): void
    {
        $result = new VatIdCheckResult(VatIdStatus::Valid, self::NOW, null, 'WAPIBBBB');

        $this->service()->record(5, 'ATU99999999', 'ATU11111111', $result);
        self::assertSame('', $this->stored()['consho_vatIdStatus']);

        $this->service()->record(5, 'DE123456789', 'ATU11111111', $result);
        self::assertSame('WAPIBBBB', $this->stored()['consho_vatIdRequestIdentifier']);
    }

    public function testStoredNurFuerGleicheUid(): void
    {
        $this->store('valid', self::NOW - 60, 'ATU11111111');

        self::assertNotNull($this->service()->stored(5, 'DE 123456789', 'ATU11111111'));
        self::assertNull($this->service()->stored(5, 'DE999999999', 'ATU11111111'));
        self::assertNull($this->service()->stored(5, 'DE123456789', 'ATU22222222'));
    }

    private function service(): MemberVatId
    {
        $validator = new class($this) implements VatIdValidatorInterface {
            public function __construct(private readonly MemberVatIdTest $test)
            {
            }

            public function check(string $vatId, string|null $requesterVatId = null): VatIdCheckResult
            {
                return $this->test->answer($vatId, $requesterVatId);
            }
        };

        return new MemberVatId($this->connection, $validator);
    }

    /**
     * @internal for the validator above
     */
    public function answer(string $vatId, string|null $requester): VatIdCheckResult
    {
        $this->queries[] = [$vatId, $requester];

        return new VatIdCheckResult($this->answer, self::NOW, null, VatIdStatus::Valid === $this->answer ? 'WAPIAAAA' : null);
    }

    private function queryCount(): int
    {
        return \count($this->queries);
    }

    private function store(string $status, int $at, string $requester): void
    {
        $this->connection->update('tl_member', [
            'consho_vatIdStatus' => $status,
            'consho_vatIdCheckedAt' => $at,
            'consho_vatIdRequestIdentifier' => 'WAPIOLD',
            'consho_vatIdRequester' => $requester,
            'consho_vatIdChecked' => 'DE123456789',
        ], ['id' => 5]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stored(): array
    {
        $row = $this->connection->fetchAssociative('SELECT consho_vatIdStatus, consho_vatIdCheckedAt, consho_vatIdRequestIdentifier, consho_vatIdRequester FROM tl_member WHERE id = 5');
        self::assertIsArray($row);

        return [
            'consho_vatIdStatus' => $row['consho_vatIdStatus'],
            'consho_vatIdCheckedAt' => null === $row['consho_vatIdCheckedAt'] ? null : (int) $row['consho_vatIdCheckedAt'],
            'consho_vatIdRequestIdentifier' => $row['consho_vatIdRequestIdentifier'],
            'consho_vatIdRequester' => $row['consho_vatIdRequester'],
        ];
    }
}
