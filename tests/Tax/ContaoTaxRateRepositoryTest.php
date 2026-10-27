<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tax\ContaoTaxRateRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class ContaoTaxRateRepositoryTest extends TestCase
{
    private const TIMEZONE = 'Europe/Berlin';

    private Connection $connection;

    private string $previousTimezone;

    protected function setUp(): void
    {
        // Contao stores an `rgxp date` as the start of the day in the default time zone.
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set(self::TIMEZONE);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // From the DCA, not copied — validFrom/validTo nullable as there.
        DcaSchema::create($this->connection, 'tl_consho_tax_rate');
        DcaSchema::create($this->connection, 'tl_consho_tax_class');
    }

    /**
     * WG3: A "not taxable" class has a rate in no country and needs none —
     * 0, without a rate being created.
     */
    public function testEineNichtSteuerbareKlasseHatUeberallNull(): void
    {
        $this->connection->insert('tl_consho_tax_class', ['id' => 7, 'title' => 'Wertgutschein', 'notTaxable' => 1]);
        $this->connection->insert('tl_consho_tax_class', ['id' => 8, 'title' => 'Bücher']);
        $repository = new ContaoTaxRateRepository($this->connection);

        self::assertSame('0.0000', $repository->findRate(7, 'AT', time()));
        self::assertSame('0.0000', $repository->findRate(7, 'JP', 0));
        self::assertNull($repository->findRate(8, 'AT', time()), 'eine gewöhnliche Klasse ohne Satz bleibt ohne Satz');
    }

    /**
     * WG3: The "not taxable" class has no rates and needs none. If it counted
     * among the shop's classes, it would narrow the shop's delivery countries
     * down to none — every order would have failed at checkout.
     */
    public function testDieNichtSteuerbareKlasseZaehltNichtZuDenKlassenDesShops(): void
    {
        foreach (['tl_consho_product', 'tl_consho_product_shop'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_tax_class', ['id' => 7, 'title' => 'Wertgutschein', 'notTaxable' => 1]);
        $this->connection->insert('tl_consho_tax_class', ['id' => 8, 'title' => 'Bücher']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'pid' => 0, 'taxClass' => 7]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 0, 'taxClass' => 8]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 1, 'shop' => 3]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 2, 'shop' => 3]);

        self::assertSame([8], (new ContaoTaxRateRepository($this->connection))->findTaxClassIdsUsedByShop(3));
    }

    /**
     * Remembered per request — reset() reads anew.
     */
    public function testDerMerkerGiltBisZumReset(): void
    {
        $this->connection->insert('tl_consho_tax_class', ['id' => 8, 'title' => 'Bücher']);
        $repository = new ContaoTaxRateRepository($this->connection);
        self::assertFalse($repository->isNotTaxable(8));

        $this->connection->update('tl_consho_tax_class', ['notTaxable' => 1], ['id' => 8]);
        self::assertFalse($repository->isNotTaxable(8));

        $repository->reset();
        self::assertTrue($repository->isNotTaxable(8));
    }

    /**
     * The form rejects two rates with the same start; through undo or a
     * restored version they come about anyway. Then the one created last
     * applies, not the chance of the database.
     */
    public function testBeiGleichemBeginnGiltDerZuletztAngelegte(): void
    {
        $this->rate('19.0000', '2026-01-01', null);
        $this->rate('16.0000', '2026-01-01', null);
        $this->rate('7.0000', null, null, 2);
        $this->rate('5.0000', null, null, 2);

        self::assertSame('16.0000', $this->normalised($this->repository()->findRate(1, 'DE', $this->at('2026-06-01 12:00:00'))));
        self::assertSame('5.0000', $this->normalised($this->repository()->findRate(2, 'DE', $this->at('2026-06-01 12:00:00'))));
    }

    /**
     * An unsaved copy (tstamp 0) is not a rate — neither for the rate nor for
     * the countries of the class.
     */
    public function testEinEntwurfIstKeinSatz(): void
    {
        $this->rate('19.0000', null, null);
        $this->rate('16.0000', '2026-01-01', null, 1, 0);

        self::assertSame('19.0000', $this->normalised($this->repository()->findRate(1, 'DE', $this->at('2026-06-01 12:00:00'))));

        $this->rate('7.0000', null, null, 2, 0);

        self::assertNull($this->repository()->findRate(2, 'DE', $this->at('2026-06-01 12:00:00')));
        self::assertSame([], $this->repository()->findConfiguredCountries(2, $this->at('2026-06-01 12:00:00')));
        self::assertSame(['DE'], $this->repository()->findConfiguredCountries(1, $this->at('2026-06-01 12:00:00')));
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->previousTimezone);
    }

    /**
     * "Valid until" is a date and includes the whole day — as it is written in
     * the legal text. Example: the German reduction 1.7.–31.12.2020.
     *
     * @return iterable<string, array{string, string|null}>
     */
    public static function momentsAroundTheGermanCut(): iterable
    {
        yield 'letzte Sekunde vor der Senkung' => ['2020-06-30 23:59:59', null];
        yield 'Beginn der Senkung' => ['2020-07-01 00:00:00', '16.0000'];
        yield 'letzter Tag, Mitternacht' => ['2020-12-31 00:00:00', '16.0000'];
        yield 'letzter Tag, Mittag' => ['2020-12-31 12:00:00', '16.0000'];
        yield 'letzter Tag, letzte Sekunde' => ['2020-12-31 23:59:59', '16.0000'];
        yield 'Tag danach' => ['2021-01-01 00:00:00', '19.0000'];
    }

    #[DataProvider('momentsAroundTheGermanCut')]
    public function testARateIsValidUpToAndIncludingTheWholeLastDay(string $moment, string|null $expected): void
    {
        $this->rate('16.0000', '2020-07-01', '2020-12-31');
        $this->rate('19.0000', '2021-01-01', null);

        self::assertSame($expected, $this->normalised($this->repository()->findRate(1, 'DE', $this->at($moment))));
    }

    /**
     * On the days of the clock change the day has 23 or 25 hours. A
     * calculation with "+ 86400 seconds" would be off by an hour there.
     *
     * @return iterable<string, array{string, string, bool}>
     */
    public static function momentsOnDaylightSavingDays(): iterable
    {
        yield 'Sommerzeit-Beginn, letzte Sekunde' => ['2026-03-29', '2026-03-29 23:59:59', true];
        yield 'Sommerzeit-Beginn, Tag danach' => ['2026-03-29', '2026-03-30 00:00:00', false];
        yield 'Sommerzeit-Ende, letzte Sekunde' => ['2026-10-25', '2026-10-25 23:59:59', true];
        yield 'Sommerzeit-Ende, Tag danach' => ['2026-10-25', '2026-10-26 00:00:00', false];
    }

    #[DataProvider('momentsOnDaylightSavingDays')]
    public function testTheLastDayEndsAtMidnightEvenWhenTheClocksChange(string $validTo, string $moment, bool $valid): void
    {
        $this->rate('20.0000', null, $validTo);

        self::assertSame($valid ? '20.0000' : null, $this->normalised($this->repository()->findRate(1, 'DE', $this->at($moment))));
    }

    public function testARateWithoutBoundariesIsAlwaysValid(): void
    {
        $this->rate('20.0000', null, null);

        self::assertSame('20.0000', $this->normalised($this->repository()->findRate(1, 'DE', $this->at('2099-01-01 12:00:00'))));
    }

    public function testConfiguredCountriesIncludeARateOnItsLastDay(): void
    {
        $this->rate('16.0000', '2020-07-01', '2020-12-31');

        self::assertSame(['DE'], $this->repository()->findConfiguredCountries(1, $this->at('2020-12-31 18:00:00')));
        self::assertSame([], $this->repository()->findConfiguredCountries(1, $this->at('2021-01-01 00:00:00')));
    }

    public function testReportsTheClassesWhoseRateBeginsOrEndsInTheWindow(): void
    {
        // End of the German reduction: 16 % applies up to and including
        // 31.12.2020 — the limit lies at 01.01.2021 00:00, not at 31.12. 00:00.
        $this->rate('16.0000', '2020-07-01', '2020-12-31', class: 1);
        $this->rate('19.0000', '2021-01-01', null, class: 2);
        $this->rate('7.0000', null, null, class: 3);

        $repository = $this->repository();

        self::assertSame([1, 2], $repository->findClassesWithBoundaryIn($this->at('2020-12-31 12:00:00'), $this->at('2021-01-01 00:00:00')));
        self::assertSame([], $repository->findClassesWithBoundaryIn($this->at('2020-12-31 00:00:00'), $this->at('2020-12-31 23:59:59')));
        self::assertSame([1], $repository->findClassesWithBoundaryIn($this->at('2020-06-30 23:00:00'), $this->at('2020-07-01 00:00:00')));
    }

    public function testABoundaryExactlyAtTheMarkerWasAlreadyApplied(): void
    {
        // (after, until]: what lay exactly at the marker, the last run has already seen.
        $this->rate('19.0000', '2021-01-01', null);

        self::assertSame([], $this->repository()->findClassesWithBoundaryIn($this->at('2021-01-01 00:00:00'), $this->at('2021-01-01 00:01:00')));
    }

    public function testTheEndOfTheLastDayHonoursTheClockChange(): void
    {
        // 25.10.2026 has 25 hours. "validTo + 86400" would lie at 23:00 — an
        // hour in which the rate still applies but the gross would already be
        // switched over.
        $this->rate('20.0000', null, '2026-10-25');

        self::assertSame([], $this->repository()->findClassesWithBoundaryIn($this->at('2026-10-25 22:00:00'), $this->at('2026-10-25 23:59:59')));
        self::assertSame([1], $this->repository()->findClassesWithBoundaryIn($this->at('2026-10-25 23:59:59'), $this->at('2026-10-26 00:00:00')));
    }

    private function repository(): ContaoTaxRateRepository
    {
        return new ContaoTaxRateRepository($this->connection);
    }

    /**
     * Creates a rate the way the backend stores it: the date as the start of the day.
     */
    private function rate(string $rate, string|null $validFrom, string|null $validTo, int $class = 1, int $tstamp = 1): void
    {
        $this->connection->insert('tl_consho_tax_rate', [
            'pid' => $class,
            'tstamp' => $tstamp,
            'country' => 'DE',
            'rate' => $rate,
            'validFrom' => null === $validFrom ? null : $this->at($validFrom.' 00:00:00'),
            'validTo' => null === $validTo ? null : $this->at($validTo.' 00:00:00'),
        ]);
    }

    private function at(string $moment): int
    {
        return (new \DateTimeImmutable($moment, new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
    }

    /**
     * SQLite returns NUMERIC as a number without decimal places, MySQL as a DECIMAL string.
     */
    private function normalised(string|null $rate): string|null
    {
        return null === $rate ? null : number_format((float) $rate, 4, '.', '');
    }
}
