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

        // Wie in der DB: validFrom/validTo NULL-fähig, customerGroup 0 = alle.
        DcaSchema::create($this->connection, 'tl_consho_price');

        // Die Währung steht ausdrücklich da, seit die Spalte keinen
        // EUR-Vorgabewert mehr hat (2026-09-21). Vorher stimmte sie nur
        // zufällig mit dem überein, wonach die Tests unten suchen.
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
     * Dieselbe Regel wie Contaos start/stop: Beginn einschließlich, Ende ausschließlich.
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

        // Gast: nur die Zeile für alle.
        self::assertSame([null], $this->groupsOf($repository->findCandidates(1, 'EUR', [], self::FROM)));

        // Mitglied in 3: seine Zeile und die für alle, nicht die von 4.
        self::assertSame([null, 3], $this->groupsOf($repository->findCandidates(1, 'EUR', [3], self::FROM)));

        // In beiden Gruppen: beide Zeilen, und der Rechner entscheidet.
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
        // ⚠️ 0 ist in der Spalte das Wort für „alle", keine Gruppen-Id. Käme
        // sie in die IN-Liste, änderte das zwar nichts am Ergebnis — aber eine
        // 0 aus tl_member.groups würde stillschweigend zu „alle" und wäre
        // später nicht mehr von einer echten Auswahl zu unterscheiden.
        $this->groupPrice(3, '8.0000');

        self::assertSame(
            [null],
            $this->groupsOf((new DbalPriceRepository($this->connection))->findCandidates(1, 'EUR', [0], self::FROM)),
        );
    }

    public function testEineNieGespeichertePreiszeileZaehltNicht(): void
    {
        // tstamp 0: die Preismaske wurde geöffnet, aber nie gespeichert.
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
        // Abo-1 (A2): Eine Preiszeile mit Aboregel ist der Preis eines anderen
        // Vertrags. Käme sie hier mit, gewänne der Monatsbetrag als günstigster
        // Preis — der Listenpreis eines 199-€-Themes wäre 10 €.
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '8.0000', 'subscriptionRule' => 5]);

        $repository = new DbalPriceRepository($this->connection);

        self::assertSame([], $repository->findCandidates(2, 'CHF', [], self::FROM));

        // Abo-2b (P88): gefunden wird das Produkt trotzdem — ein reines
        // Abo-Produkt steht mit seinem ersten Modell in der Projektion, und
        // die fragt nur Produkte und Währungen von hier.
        self::assertContains(2, $repository->findProductIdsWithPrices());
        self::assertContains('CHF', $repository->findCurrencies());
    }

    public function testEinAbopreisWirdNachSeinerRegelGefragt(): void
    {
        // Abo-2: Die Regel ist eine Dimension der Abfrage — gefragt nach Regel 5
        // kommt genau der Vertrag 5, nie der Einmalkauf dazu (und umgekehrt).
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '80.0000']);
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '8.0000', 'subscriptionRule' => 5]);
        $this->connection->insert('tl_consho_price', ['pid' => 2, 'tstamp' => 1, 'currency' => 'CHF', 'net' => '70.0000', 'subscriptionRule' => 6]);

        $repository = new DbalPriceRepository($this->connection);

        // Als Zahl verglichen: SQLite streicht die Nullen, MySQL nicht (Fall 30).
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
        // Sortiert, weil die Zeilenreihenfolge der Engine gehört und nicht
        // Gegenstand dieser Tests ist — hier zählt, WELCHE Zeilen kommen.
        $groups = array_map(static fn ($row) => $row->customerGroup, $rows);
        sort($groups);

        return $groups;
    }
}
