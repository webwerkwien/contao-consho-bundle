<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Privacy\RetentionPolicy;

/**
 * D-5, L2/L6 — the period begins at the close of the calendar year of the
 * most recent record (§ 132 Abs. 1 BAO; § 147 Abs. 4 AO), without a record
 * with the order date, and ends on 31.12. of the year "record year + N".
 */
final class RetentionPolicyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = PrivacySchema::create();
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien', 'retentionYears' => 7, 'autoAnonymize' => 1]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Berlin', 'retentionYears' => 0, 'autoAnonymize' => 1]);
        $this->connection->insert('tl_consho_shop', ['id' => 3, 'title' => 'Aus', 'retentionYears' => 7, 'autoAnonymize' => 0]);
    }

    public function testDasFristendeIstDerSchlussDesJahresPlusN(): void
    {
        self::assertSame(mktime(23, 59, 59, 12, 31, 2033), RetentionPolicy::endOf(strtotime('2026-01-01 00:00'), 7));
        self::assertSame(mktime(23, 59, 59, 12, 31, 2033), RetentionPolicy::endOf(strtotime('2026-12-31 23:00'), 7));
        self::assertSame(mktime(23, 59, 59, 12, 31, 2034), RetentionPolicy::endOf(strtotime('2026-06-01'), 8));
    }

    public function testDerJuengsteBelegZaehlt(): void
    {
        $this->order(10, 1, '2018-05-01');
        // Numbered as real documents are: shop,type,documentNumber is unique.
        $this->connection->insert('tl_consho_document', ['pid' => 10, 'documentNumber' => 'RE-00001', 'issuedAt' => strtotime('2018-05-02')]);
        $this->connection->insert('tl_consho_document', ['pid' => 10, 'type' => 'cancellation', 'documentNumber' => 'ST-00001', 'issuedAt' => strtotime('2019-02-01')]); // cancellation in the following year

        self::assertSame(mktime(23, 59, 59, 12, 31, 2026), $this->policy()->endOfOrder(10));
        self::assertSame([], $this->ids($this->policy()->due(strtotime('2026-12-31 12:00'))), 'am letzten Tag noch nicht');
        self::assertSame([10], $this->ids($this->policy()->due(strtotime('2027-01-01 00:00'))));
    }

    public function testOhneBelegDasBestelldatum(): void
    {
        $this->order(11, 1, '2019-03-01');

        self::assertSame(mktime(23, 59, 59, 12, 31, 2026), $this->policy()->endOfOrder(11));
    }

    public function testNurMitFristUndNurWasFaelligIst(): void
    {
        $this->order(20, 1, '2010-01-01');                        // due
        $this->order(21, 1, '2025-01-01');                        // not for a long time
        $this->order(22, 2, '2010-01-01');                        // shop without a period
        $this->order(23, 3, '2010-01-01');                        // shop without automation, no request
        $this->order(24, 3, '2010-01-01', erasureRequested: true); // shop without automation, request
        $this->order(25, 1, '2010-01-01', anonymized: true);      // already done

        $due = $this->policy()->due(strtotime('2026-09-28'));

        self::assertSame([20, 24], $this->ids($due));
        self::assertSame([20, 23, 24], $this->ids($this->policy()->due(strtotime('2026-09-28'), onlyAutomatic: false)), 'ohne Rücksicht auf die Automatik (für den Antrag)');
    }

    public function testFaelligInDenNaechstenTagen(): void
    {
        $this->order(30, 1, '2019-06-01');   // ends 31.12.2026
        $this->order(31, 1, '2020-06-01');   // ends 31.12.2027

        self::assertSame([30], $this->ids($this->policy()->dueBetween(strtotime('2026-12-15'), strtotime('2027-01-14'))));
        self::assertSame([], $this->ids($this->policy()->dueBetween(strtotime('2026-09-28'), strtotime('2026-10-28'))));
    }

    private function order(int $id, int $shop, string $date, bool $erasureRequested = false, bool $anonymized = false): void
    {
        $this->connection->insert('tl_consho_order', [
            'id' => $id, 'shop' => $shop, 'orderNumber' => 'WEB-'.$id, 'createdAt' => strtotime($date),
            'erasureRequestedAt' => $erasureRequested ? 1 : null, 'anonymizedAt' => $anonymized ? 1 : null,
        ]);
    }

    /**
     * @param list<array{id: int}> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_column($rows, 'id');
    }

    private function policy(): RetentionPolicy
    {
        return new RetentionPolicy($this->connection);
    }
}
