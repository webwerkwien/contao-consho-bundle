<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\OperatorCancellationDates;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPriceCheck;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-10: which subscription gets a price offer, for which day and with
 * which lead time, and whether the price changed (P114, P115, P117, P127,
 * P136).
 */
class SubscriptionPriceCheckTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    /**
     * @var list<array{int, int, list<int>}>
     */
    private array $allowsAsked = [];

    /**
     * @var list<array{int, int, string, string, list<int>, int}>
     */
    private array $pricesAsked = [];

    private bool $allowed = true;

    private string|null $price = '12.0000';

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY, "groups" TEXT)');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'subscriptionOperatorDays' => 56]);
        $this->connection->insert('tl_member', ['id' => 15, 'groups' => serialize(['2', '5'])]);
    }

    public function testKandidatNurLaufendOhneEndeUndNichtVerlaengert(): void
    {
        self::assertTrue(SubscriptionPriceCheck::candidate(self::row()));
        self::assertTrue(SubscriptionPriceCheck::candidate(['state' => 'suspended'] + self::row()));
        self::assertTrue(SubscriptionPriceCheck::candidate(['state' => 'paused'] + self::row()));
        self::assertFalse(SubscriptionPriceCheck::candidate(['state' => 'ended'] + self::row()));
        self::assertFalse(SubscriptionPriceCheck::candidate(['endsAt' => self::at('2027-01-15 10:00')] + self::row()));
        self::assertFalse(SubscriptionPriceCheck::candidate(['renewedBy' => 9] + self::row()));
        // P114: "Endet" runs out, renewed at today's price.
        self::assertFalse(SubscriptionPriceCheck::candidate(['renewal' => 'ends'] + self::row()));
        // A rule from before variant A ends with its term.
        self::assertFalse(SubscriptionPriceCheck::candidate(['renewal' => 'indefinite', 'billingInterval' => 12] + self::row()));
        self::assertTrue(SubscriptionPriceCheck::candidate(['renewal' => 'indefinite'] + self::row()));
    }

    public function testVorlaufMindestensKuendigungsfristPlusZweiWochen(): void
    {
        self::assertSame(56, $this->check()->leadDays(self::row()));
        self::assertSame(74, $this->check()->leadDays(['noticeDays' => 60] + self::row()));

        $this->connection->update('tl_consho_shop', ['subscriptionOperatorDays' => 90], ['id' => 1]);
        self::assertSame(90, $this->check()->leadDays(self::row()));

        // No shop row: the default.
        self::assertSame(56, $this->check()->leadDays(['shop' => 7] + self::row()));
    }

    public function testGleicheLaufzeitAmVorlauftagDasLaufzeitende(): void
    {
        $row = self::row();
        $end = self::at('2027-01-15 10:00');
        $leadDay = (new SubscriptionCalendar(self::ZONE))->addDays($end, -56);

        self::assertNull($this->check()->effectiveDay($row, 56, $leadDay - 3600), 'not before the lead time is reached');
        self::assertSame($end, $this->check()->effectiveDay($row, 56, $leadDay));
        // A run an hour late still finds it.
        self::assertSame($end, $this->check()->effectiveDay($row, 56, $leadDay + 3600));
    }

    /**
     * P117: found too late for the lead time, the offer is for the next date —
     * and is made once that one's lead time is reached, not now.
     */
    public function testZuSpaetGefundenGiltDerNaechsteTermin(): void
    {
        $row = self::row();
        $calendar = new SubscriptionCalendar(self::ZONE);

        self::assertNull($this->check()->effectiveDay($row, 56, self::at('2026-12-01 10:00')));

        $next = self::at('2028-01-15 10:00');
        self::assertSame($next, $this->check()->effectiveDay($row, 56, $calendar->addDays($next, -56)));
    }

    public function testUnbefristetDiePeriodengrenzeMitVorlauf(): void
    {
        $row = ['renewal' => 'indefinite', 'phase' => 'indefinite'] + self::row();
        $calendar = new SubscriptionCalendar(self::ZONE);
        $boundary = self::at('2027-05-15 10:00');

        self::assertSame($boundary, $this->check()->effectiveDay($row, 56, $calendar->addDays($boundary, -56)));
        self::assertNull($this->check()->effectiveDay($row, 56, $calendar->addDays($boundary, -56) - 3600));
    }

    public function testEinVorlaufVonMonatenErreichtSeineGrenze(): void
    {
        $row = ['renewal' => 'indefinite', 'phase' => 'indefinite'] + self::row();
        $calendar = new SubscriptionCalendar(self::ZONE);
        $boundary = self::at('2027-05-15 10:00');

        self::assertSame($boundary, $this->check()->effectiveDay($row, 250, $calendar->addDays($boundary, -250)));
    }

    public function testEndetBekommtKeinenTermin(): void
    {
        $row = ['renewal' => 'ends'] + self::row();

        self::assertNull($this->check()->effectiveDay($row, 56, (new SubscriptionCalendar(self::ZONE))->addDays(self::at('2027-01-15 10:00'), -56)));
    }

    /**
     * The mail names a day: the deadline runs to its end — never past the
     * effective moment (a notice of zero days).
     */
    public function testFristIstDerTagMinusKuendigungsfristBisTagesende(): void
    {
        self::assertSame(self::at('2026-12-16 23:59:59'), $this->check()->deadline(self::row(), self::at('2027-01-15 10:00')));
        self::assertSame(self::at('2027-01-15 09:59:59'), $this->check()->deadline(['noticeDays' => 0] + self::row(), self::at('2027-01-15 10:00')));
        // Summer time: the end of the day is local.
        self::assertSame(self::at('2027-06-01 23:59:59'), $this->check()->deadline(self::row(), self::at('2027-07-01 10:00')));
    }

    public function testErinnerungAbTagesbeginnSiebenTageVorher(): void
    {
        self::assertSame(self::at('2026-12-09 00:00'), $this->check()->reminderFrom(self::at('2026-12-16 23:59:59')));
    }

    public function testHeutigerPreisMitDenGruppenDesKontos(): void
    {
        self::assertSame('12.0000', $this->check()->currentUnitNet(self::row(), 1000));
        self::assertSame([[40, 3, [2, 5]]], $this->allowsAsked);
        // The variant's main product, the subscription's currency, quantity and plan.
        self::assertSame([[40, 4, 'EUR', '2.0000', [2, 5], 3]], $this->pricesAsked);
    }

    public function testNichtMehrAngebotenOderOhnePreisOderOhneKonto(): void
    {
        $this->allowed = false;
        self::assertNull($this->check()->currentUnitNet(self::row(), 1000));
        self::assertSame([], $this->pricesAsked);

        $this->allowed = true;
        $this->price = null;
        self::assertNull($this->check()->currentUnitNet(self::row(), 1000));

        $this->price = '12.0000';
        self::assertNull($this->check()->currentUnitNet(['member' => 99] + self::row(), 1000));
    }

    public function testGeaendertErstAufDenStellenDerWaehrung(): void
    {
        self::assertFalse(SubscriptionPriceCheck::changed('10.0000', '10.0040', 'EUR'));
        self::assertTrue(SubscriptionPriceCheck::changed('10.0000', '10.0050', 'EUR'));
        self::assertTrue(SubscriptionPriceCheck::changed('10.0000', '9.9900', 'EUR'));
        // No minor unit: whole yen.
        self::assertFalse(SubscriptionPriceCheck::changed('1000.0000', '1000.4000', 'JPY'));
        self::assertTrue(SubscriptionPriceCheck::changed('1000.0000', '1000.6000', 'JPY'));
    }

    private function check(): SubscriptionPriceCheck
    {
        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('allows')->willReturnCallback(function (int $article, int $rule, array $groups): bool {
            $this->allowsAsked[] = [$article, $rule, $groups];

            return $this->allowed;
        });

        $prices = $this->createMock(CartLinePriceSource::class);
        $prices->method('forCartLine')->willReturnCallback(function (int $article, int $main, string $currency, string $quantity, int $now, array $groups, int $rule): CartLinePrice|null {
            $this->pricesAsked[] = [$article, $main, $currency, $quantity, $groups, $rule];

            return null === $this->price ? null : new CartLinePrice($this->price, 1);
        });

        $articles = $this->createMock(ArticleTreeInterface::class);
        $articles->method('articlesOf')->willReturn([40 => 4]);

        $calendar = new SubscriptionCalendar(self::ZONE);

        return new SubscriptionPriceCheck($this->connection, $calendar, new OperatorCancellationDates($calendar), $offers, $prices, $articles);
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(): array
    {
        return [
            'subscriptionNumber' => 'WEB-00001-1',
            'state' => 'active',
            'renewal' => 'sameTerm',
            'billingInterval' => 1,
            'phase' => 'minimum',
            'startedAt' => self::at('2026-01-15 10:00'),
            'termLength' => 12,
            'termUnit' => 'month',
            'minimumEndsAt' => self::at('2027-01-15 10:00'),
            'pausedMonths' => 0,
            'noticeDays' => 30,
            'endsAt' => 0,
            'renewedBy' => 0,
            'shop' => 1,
            'member' => 15,
            'product' => 40,
            'rule' => 3,
            'currency' => 'EUR',
            'quantity' => '2.0000',
        ];
    }

    private static function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }
}
