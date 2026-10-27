<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalPeriodIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * W4: A shop in the EU whose period is below the default gets a warning.
 * The delivery country decides.
 */
final class WithdrawalPeriodIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        unset($GLOBALS['TL_LANG']);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    /**
     * @return iterable<string, array{int, list<string>|null, string|null}>
     */
    public static function faelle(): iterable
    {
        yield '14 Tage in die EU' => [14, ['AT', 'DE'], null];
        yield '30 Tage' => [30, ['AT'], null];
        yield '7 Tage nach Österreich' => [7, ['AT', 'CH'], 'AT'];
        yield '7 Tage nach Norwegen (EWR)' => [7, ['NO'], 'NO'];
        yield '7 Tage nur in die Schweiz' => [7, ['CH'], null];
        yield '0 Tage in die EU' => [0, ['DE'], 'DE'];
        yield '0 Tage nur in die Schweiz' => [0, ['CH'], null];
        yield 'keine Lieferländer heißt überallhin' => [7, null, 'all'];
    }

    /**
     * @param list<string>|null $countries
     *
     * @dataProvider faelle
     */
    public function testWarntNurUnter14TagenMitLieferlandInEuOderEwr(int $days, array|null $countries, string|null $expected): void
    {
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Testshop', 'withdrawalDays' => $days, 'deliveryCountries' => null === $countries ? null : serialize($countries)]);

        $issues = (new WithdrawalPeriodIssueSource($this->connection))->issues();

        if (null === $expected) {
            self::assertSame([], $issues);

            return;
        }

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::WithdrawalPeriodShort, $issues[0]->finding);
        self::assertSame(['shop' => 'Testshop', 'days' => (string) $days, 'countries' => $expected], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
        self::assertTrue($issues[0]->finding->isWarning());
    }
}
