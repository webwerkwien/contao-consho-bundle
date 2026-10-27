<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\DbalPriceRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class DbalPriceRepositoryTest extends TestCase
{
    private const FROM = 1_790_000_000;
    private const TO = 1_790_086_400;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // As in the DB: validFrom/validTo nullable, customerGroup 0 = everyone.
        DcaSchema::create($this->connection, 'tl_consho_price');

        // The currency is stated explicitly, since the column no longer has an
        // EUR default. Before that it only matched by
        // chance what the tests below search for.
        $this->connection->insert('tl_consho_price', [
            'pid' => 1,
            'tstamp' => 1,
            'currency' => 'EUR',
            'net' => '10.0000',
            'validFrom' => self::FROM,
            'validTo' => self::TO,
        ]);
    }

    /**
     * The same rule as Contao's start/stop: start inclusive, end exclusive.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function moments(): iterable
    {
        yield 'eine Sekunde vor Beginn' => [self::FROM - 1, false];
        yield 'genau zum Beginn' => [self::FROM, true];
        yield 'eine Sekunde vor Ende' => [self::TO - 1, true];
        yield 'genau zum Ende' => [self::TO, false];
    }

    #[DataProvider('moments')]
    public function testAPriceIsValidFromItsStartUpToButNotIncludingItsEnd(int $timestamp, bool $valid): void
    {
        $candidates = (new DbalPriceRepository($this->connection))->findCandidates(1, 'EUR', [], $timestamp);

        self::assertCount($valid ? 1 : 0, $candidates);
    }

    public function testAPriceWithoutBoundariesIsAlwaysValid(): void
    {
        $this->connection->update('tl_consho_price', ['validFrom' => null, 'validTo' => null], ['pid' => 1]);

        self::assertCount(1, (new DbalPriceRepository($this->connection))->findCandidates(1, 'EUR', [], self::TO + 1));
    }

    public function testEineGruppenzeileErreichtNurIhreEigeneGruppe(): void
    {
        $this->groupPrice(3, '8.0000');
        $this->groupPrice(4, '7.0000');

        $repository = new DbalPriceRepository($this->connection);

        // Guest: only the row for everyone.
        self::assertSame([null], $this->groupsOf($repository->findCandidates(1, 'EUR', [], self::FROM)));

        // Member of 3: their row and the one for everyone, not that of 4.
        self::assertSame([null, 3], $this->groupsOf($repository->findCandidates(1, 'EUR', [3], self::FROM)));

        // In both groups: both rows, and the calculator decides.
        self::assertSame([null, 3, 4], $this->groupsOf($repository->findCandidates(1, 'EUR', [3, 4], self::FROM)));
    }

    public function testEineUnbekannteGruppeLiefertNurDieZeileFuerAlle(): void
    {
        $this->groupPrice(3, '8.0000');

        self::assertSame(
            [null],
            $this->groupsOf((new DbalPriceRepository($this->connection))->findCandidates(1, 'EUR', [99], self::FROM)),
        );
    }

    public function testDieNullAlsGruppeIstKeineGruppe(): void
    {
        // ⚠️ In the column 0 is the word for "everyone", not a group id. If it
        // went into the IN list, that would not change the result — but a
        // 0 from tl_member.groups would silently become "everyone" and could
        // later no longer be told apart from a real selection.
        $this->groupPrice(3, '8.0000');

        self::assertSame(
            [null],
            $this->groupsOf((new DbalPriceRepository($this->connection))->findCandidates(1, 'EUR', [0], self::FROM)),
        );
    }

    public function testEineNieGespeichertePreiszeileZaehltNicht(): void
    {
        // tstamp 0: the price form was opened but never saved.
        $this->connection->insert('tl_consho_price', [
            'pid' => 2,
            'tstamp' => 0,
            'currency' => 'CHF',
            'net' => '0.0000',
        ]);

        $repository = new DbalPriceRepository($this->connection);

        self::assertSame([], $repository->findCandidates(2, 'CHF', [], self::FROM));
        self::assertNotContains(2, $repository->findProductIdsWithPrices());
        self::assertNotContains('CHF', $repository->findCurrencies());
    }

    public function testEinAbopreisIstKeinPreisFuerDenEinmalkauf(): void
    {
        // Abo-1 (A2): a price row with a subscription rule is the price of another
        // contract. If it came along here, the monthly amount would win as the cheapest
        // price — the list price of a €199 theme would be €10.
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '8.0000', 'subscriptionRule' => 5]);

        $repository = new DbalPriceRepository($this->connection);

        self::assertSame([], $repository->findCandidates(2, 'CHF', [], self::FROM));

        // Abo-2b (P88): the product is found nevertheless — a subscription-only
        // product is in the projection with its first model, and
        // the projection asks only for products and currencies from here.
        self::assertContains(2, $repository->findProductIdsWithPrices());
        self::assertContains('CHF', $repository->findCurrencies());
    }

    public function testEinAbopreisWirdNachSeinerRegelGefragt(): void
    {
        // Abo-2: the rule is a dimension of the query — asked for rule 5,
        // exactly contract 5 comes back, never the one-off purchase as well (and vice versa).
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '80.0000']);
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '8.0000', 'subscriptionRule' => 5]);
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '70.0000', 'subscriptionRule' => 6]);

        $repository = new DbalPriceRepository($this->connection);

        // Compared as a number: SQLite strips the zeros, MySQL does not.
        self::assertSame([8.0], array_map(static fn ($row) => (float) $row->net, $repository->findCandidates(2, 'CHF', [], self::FROM, 5)));
        self::assertSame([80.0], array_map(static fn ($row) => (float) $row->net, $repository->findCandidates(2, 'CHF', [], self::FROM)));
    }

    private function groupPrice(int $group, string $net): void
    {
        $this->connection->insert('tl_consho_price', [
            'pid' => 1,
            'tstamp' => 1,
            'currency' => 'EUR',
            'net' => $net,
            'customerGroup' => $group,
            'validFrom' => self::FROM,
            'validTo' => self::TO,
        ]);
    }

    /**
     * @param list<\Webwerkwien\ContaoConshoBundle\Pricing\PriceRow> $rows
     *
     * @return list<int|null>
     */
    private function groupsOf(array $rows): array
    {
        // Sorted, because the row order belongs to the engine and is not
        // the subject of these tests — what counts here is WHICH rows come.
        $groups = array_map(static fn ($row) => $row->customerGroup, $rows);
        sort($groups);

        return $groups;
    }
}
