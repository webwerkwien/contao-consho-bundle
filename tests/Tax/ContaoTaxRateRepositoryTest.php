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
        // Contao speichert ein `rgxp date` als Tagesbeginn in der Standard-Zeitzone.
        $this->previousTimezone = date_default_timezone_get();
        date_default_timezone_set(self::TIMEZONE);

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        // Aus der DCA, nicht abgeschrieben (Fall 57) — validFrom/validTo NULL-fähig wie dort.
        DcaSchema::create($this->connection, 'tl_consho_tax_rate');
        DcaSchema::create($this->connection, 'tl_consho_tax_class');
    }

    /**
     * WG3: Eine Klasse „nicht steuerbar" hat in keinem Land einen Satz und
     * braucht keinen — 0, ohne dass ein Satz angelegt ist.
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
     * WG3: Die Klasse „nicht steuerbar" hat keine Sätze und braucht keine.
     * Zählte sie zu den Klassen des Shops, engte sie seine Lieferländer auf
     * keines ein — jede Bestellung wäre an der Kasse gescheitert.
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
     * Gemerkt wird je Anfrage — reset() liest neu.
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
     * Zwei Sätze mit demselben Beginn lehnt die Maske ab; über Undo oder
     * eine wiederhergestellte Version entstehen sie trotzdem. Dann gilt der
     * zuletzt angelegte, nicht der Zufall der Datenbank.
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
     * Eine ungespeicherte Kopie (tstamp 0) ist kein Satz — weder für den Satz
     * noch für die Länder der Klasse.
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
     * „Gültig bis" ist ein Datum und schließt den ganzen Tag ein — so, wie es im
     * Gesetzestext steht. Beispiel: die deutsche Senkung 1.7.–31.12.2020.
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
     * An den Tagen der Zeitumstellung hat der Tag 23 bzw. 25 Stunden. Eine
     * Rechnung mit „+ 86400 Sekunden" läge dort eine Stunde daneben.
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
        // Ende der deutschen Senkung: 16 % gilt bis einschließlich 31.12.2020 —
        // die Grenze liegt am 01.01.2021 um 00:00, nicht am 31.12. um 00:00.
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
        // (after, until]: Was genau am Marker lag, hat der letzte Lauf schon gesehen.
        $this->rate('19.0000', '2021-01-01', null);

        self::assertSame([], $this->repository()->findClassesWithBoundaryIn($this->at('2021-01-01 00:00:00'), $this->at('2021-01-01 00:01:00')));
    }

    public function testTheEndOfTheLastDayHonoursTheClockChange(): void
    {
        // Der 25.10.2026 hat 25 Stunden. „validTo + 86400" läge um 23:00 — eine
        // Stunde, in der der Satz noch gilt, das Brutto aber schon umgestellt wäre.
        $this->rate('20.0000', null, '2026-10-25');

        self::assertSame([], $this->repository()->findClassesWithBoundaryIn($this->at('2026-10-25 22:00:00'), $this->at('2026-10-25 23:59:59')));
        self::assertSame([1], $this->repository()->findClassesWithBoundaryIn($this->at('2026-10-25 23:59:59'), $this->at('2026-10-26 00:00:00')));
    }

    private function repository(): ContaoTaxRateRepository
    {
        return new ContaoTaxRateRepository($this->connection);
    }

    /**
     * Legt einen Satz so an, wie das Backend ihn speichert: Datum als Tagesbeginn.
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
     * SQLite liefert NUMERIC als Zahl ohne Nachkommastellen zurück, MySQL als DECIMAL-String.
     */
    private function normalised(string|null $rate): string|null
    {
        return null === $rate ? null : number_format((float) $rate, 4, '.', '');
    }
}
