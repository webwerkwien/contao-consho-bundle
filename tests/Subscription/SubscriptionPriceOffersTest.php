<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationTerm;
use Webwerkwien\ContaoConshoBundle\Cancellation\OperatorCancellationDates;
use Webwerkwien\ContaoConshoBundle\Cancellation\SubscriptionContact;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPriceCheck;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPriceOffers;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-10: an offer is made once, for one day, under the lock (P114, P116,
 * P124, P135).
 */
final class SubscriptionPriceOffersTest extends TestCase
{
    private const ZONE = 'Europe/Vienna';

    private Connection $connection;

    private string|null $price = '12.0000';

    private int $leadDay;

    private int $end;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_subscription_price', 'tl_consho_shop', 'tl_consho_cancellation', 'tl_consho_order'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->executeStatement('CREATE TABLE tl_member (id INTEGER PRIMARY KEY, "groups" TEXT)');
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'subscriptionOperatorDays' => 56]);
        $this->connection->insert('tl_member', ['id' => 15, 'groups' => serialize(['2'])]);
        $this->connection->insert('tl_consho_subscription', [
            'id' => 1,
            'subscriptionNumber' => 'WEB-00001-1',
            'state' => 'active',
            'renewal' => 'sameTerm',
            'billingInterval' => 1,
            'phase' => 'minimum',
            'startedAt' => self::at('2026-01-15 10:00'),
            'termLength' => 12,
            'termUnit' => 'month',
            'minimumEndsAt' => self::at('2027-01-15 10:00'),
            'noticeDays' => 30,
            'shop' => 1,
            'member' => 15,
            'product' => 40,
            'rule' => 3,
            'currency' => 'EUR',
            'quantity' => '2.0000',
            'unitNet' => '10.0000',
        ]);

        $this->end = self::at('2027-01-15 10:00');
        $this->leadDay = (new SubscriptionCalendar(self::ZONE))->addDays($this->end, -56);
    }

    public function testErhoehungWirdOffenAngeboten(): void
    {
        $due = $this->offers()->prepare(1, $this->leadDay);

        self::assertNotNull($due);
        self::assertSame('offer', $due->event);
        self::assertSame('WEB-00001-1', $due->subscriptionNumber);
        self::assertSame($this->end, $due->effectiveAt);
        self::assertSame(self::at('2026-12-16 23:59:59'), $due->deadlineAt);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription_price WHERE id = ?', [$due->offerId]);
        self::assertIsArray($row);
        self::assertSame('open', $row['state']);
        self::assertSame(0, (int) $row['decrease']);
        self::assertSame(0, (int) $row['mailSentAt'], 'counts only once its mail is out (P135)');
        // SQLite drops the trailing zeros MySQL keeps.
        self::assertSame(12.0, (float) $row['newUnitNet']);
        self::assertSame('12.0000', $due->newUnitNet);
    }

    /**
     * P116: a decrease needs no answer.
     */
    public function testSenkungGiltSofortAlsAngenommen(): void
    {
        $this->price = '8.0000';
        $due = $this->offers()->prepare(1, $this->leadDay);

        self::assertNotNull($due);
        self::assertSame('decrease', $due->event);
        self::assertSame('accepted', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription_price WHERE id = ?', [$due->offerId]));
    }

    public function testUnveraendertOderNichtMehrPreisKeinAngebot(): void
    {
        $this->price = '10.0040';
        self::assertNull($this->offers()->prepare(1, $this->leadDay));

        $this->price = null;
        self::assertNull($this->offers()->prepare(1, $this->leadDay));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_subscription_price'));
    }

    public function testVorDemVorlaufOderOhneKandidatNichts(): void
    {
        self::assertNull($this->offers()->prepare(1, $this->leadDay - 3600));

        $this->connection->update('tl_consho_subscription', ['renewal' => 'ends'], ['id' => 1]);
        self::assertNull($this->offers()->prepare(1, $this->leadDay));
        self::assertNull($this->offers()->prepare(99, $this->leadDay));
    }

    /**
     * P124: one offer per day; and none while another still waits.
     */
    public function testNurEinAngebotJeTagUndNichtWaehrendEinesOffenen(): void
    {
        $due = $this->offers()->prepare(1, $this->leadDay);
        self::assertNotNull($due);
        $this->offers()->sent($due->offerId, $this->leadDay);

        self::assertNull($this->offers()->prepare(1, $this->leadDay + 3600));

        // Agreed, not yet billed: still waiting.
        $this->connection->update('tl_consho_subscription_price', ['state' => 'accepted'], ['id' => $due->offerId]);
        self::assertNull($this->offers()->prepare(1, $this->leadDay + 7200));

        // Declined: settled, but the day has its row.
        $this->connection->update('tl_consho_subscription_price', ['state' => 'declined'], ['id' => $due->offerId]);
        self::assertNull($this->offers()->prepare(1, $this->leadDay + 7200));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_subscription_price'));
    }

    /**
     * P135: withdraw() takes back only an offer whose mail did not leave.
     */
    public function testZurueckNurOhneVersand(): void
    {
        $offers = $this->offers();
        $due = $offers->prepare(1, $this->leadDay);
        self::assertNotNull($due);

        $offers->withdraw($due->offerId);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_subscription_price'));

        // The next run makes it anew.
        $again = $offers->prepare(1, $this->leadDay + 3600);
        self::assertNotNull($again);
        $offers->sent($again->offerId, $this->leadDay + 3600);
        $offers->withdraw($again->offerId);

        self::assertSame($this->leadDay + 3600, (int) $this->connection->fetchOne('SELECT mailSentAt FROM tl_consho_subscription_price WHERE id = ?', [$again->offerId]));
    }

    /**
     * A row whose mail never went out — the run died between prepare() and
     * sent() — blocks only for a while: after STALE_SECONDS it is made anew.
     */
    public function testLiegengebliebenesAngebotOhneVersandWirdNeuGemacht(): void
    {
        $offers = $this->offers();
        $stale = $offers->prepare(1, $this->leadDay);
        self::assertNotNull($stale);

        self::assertNull($offers->prepare(1, $this->leadDay + SubscriptionPriceOffers::STALE_SECONDS - 1), 'a fresh one may still be on its way');

        $again = $offers->prepare(1, $this->leadDay + SubscriptionPriceOffers::STALE_SECONDS + 1);
        self::assertNotNull($again);
        self::assertNotSame($stale->offerId, $again->offerId);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_subscription_price'));

        // The slow run's mail went out after all: its mark finds nothing and says so.
        try {
            $offers->sent($stale->offerId, $this->leadDay + SubscriptionPriceOffers::STALE_SECONDS + 2);
            self::fail('The mark of a cleared offer should fail.');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('made it anew', $e->getMessage());
        }
    }

    /**
     * P118: once, seven days before the deadline, while unanswered.
     */
    public function testErinnerungSiebenTageVorDerFrist(): void
    {
        $offerId = $this->sentOffer();
        $calendar = new SubscriptionCalendar(self::ZONE);
        $deadline = self::at('2026-12-16 23:59:59');
        // From the start of the day, seven days before the deadline's.
        $from = self::at('2026-12-09 00:00');

        self::assertNull($this->offers()->remind($offerId, $from - 1));

        $reminder = $this->offers()->remind($offerId, $from);
        self::assertNotNull($reminder);
        self::assertSame('reminder', $reminder->event);
        self::assertSame($deadline, $reminder->deadlineAt);
        self::assertNull($this->offers()->remind($offerId, $from + 3600), 'once');

        // A reminder whose mail did not leave is tried again.
        $this->offers()->unremind($offerId);
        self::assertNotNull($this->offers()->remind($offerId, $from + 3600));

        // Not at or after the deadline: then it lapses.
        $this->offers()->unremind($offerId);
        self::assertNull($this->offers()->remind($offerId, $deadline));
    }

    /**
     * P125 with P118: a pause moves the deadline — reminded again before the
     * new one; withdrawn, the deadline is back and so is the reminder sent.
     */
    public function testErinnerungJeFristAuchNachPauseUndRueckzug(): void
    {
        $offerId = $this->sentOffer();
        $calendar = new SubscriptionCalendar(self::ZONE);
        $deadline = self::at('2026-12-16 23:59:59');
        $from = self::at('2026-12-09 00:00');
        self::assertNotNull($this->offers()->remind($offerId, $from));

        $later = self::at('2027-03-16 10:00');
        $this->connection->update('tl_consho_subscription_price', ['deadlineAt' => $later, 'effectiveAt' => $calendar->addDays($later, 28)], ['id' => $offerId]);
        self::assertNotNull($this->offers()->remind($offerId, $calendar->addDays($later, -7)), 'the later deadline has its own');

        // Withdrawn before the new reminder: the first one counts again.
        $this->connection->update('tl_consho_subscription_price', ['reminderSentAt' => $from, 'deadlineAt' => $deadline, 'effectiveAt' => $calendar->addDays($deadline, 28)], ['id' => $offerId]);
        self::assertNull($this->offers()->remind($offerId, $from + 3600));
    }

    /**
     * P122: a business customer's silence is consent.
     */
    public function testSchweigenEinesGeschaeftskundenIstZustimmung(): void
    {
        $this->connection->update('tl_consho_subscription', ['businessCustomer' => 1], ['id' => 1]);
        $offerId = $this->sentOffer();
        $deadline = self::at('2026-12-16 23:59:59');

        self::assertNull($this->offers()->lapse($offerId, $deadline - 3600), 'not before the deadline');

        $lapse = $this->offers()->lapse($offerId, $deadline);
        self::assertNotNull($lapse);
        self::assertSame('lapsedBusiness', $lapse->event);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription_price WHERE id = ?', [$offerId]);
        self::assertIsArray($row);
        self::assertSame(['accepted', 'silence', $deadline], [$row['state'], $row['answeredBy'], (int) $row['answeredAt']]);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT endsAt FROM tl_consho_subscription WHERE id = 1'));
        self::assertNull($this->offers()->lapse($offerId, $deadline + 3600), 'once');
    }

    /**
     * P122: a consumer's subscription ends at the effective day — a
     * cancellation from `priceChange`, confirmed by the offer's own mail.
     */
    public function testSchweigenEinesVerbrauchersBeendetDasAbo(): void
    {
        $offerId = $this->sentOffer();
        $deadline = self::at('2026-12-16 23:59:59');

        $lapse = $this->offers()->lapse($offerId, $deadline);
        self::assertNotNull($lapse);
        self::assertSame('lapsedConsumer', $lapse->event);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription_price WHERE id = ?', [$offerId]);
        self::assertIsArray($row);
        self::assertSame(['lapsed', 'silence'], [$row['state'], $row['answeredBy']]);

        $cancellation = $this->connection->fetchAssociative('SELECT * FROM tl_consho_cancellation WHERE id = ?', [(int) $row['cancellation']]);
        self::assertIsArray($cancellation);
        self::assertSame(['priceChange', 'ordinary', 'effective', 'WEB-00001-1-K1'], [$cancellation['source'], $cancellation['kind'], $cancellation['state'], $cancellation['number']]);
        self::assertSame($this->end, (int) $cancellation['effectiveAt']);
        self::assertSame($this->end, (int) $this->connection->fetchOne('SELECT endsAt FROM tl_consho_subscription WHERE id = 1'));
        self::assertEmpty($cancellation['confirmationSentAt']);

        $this->offers()->answerSent($offerId, $deadline + 60);
        self::assertSame($deadline + 60, (int) $this->connection->fetchOne('SELECT confirmationSentAt FROM tl_consho_cancellation WHERE id = ?', [(int) $row['cancellation']]));
        self::assertSame($deadline + 60, (int) $this->connection->fetchOne('SELECT answerMailSentAt FROM tl_consho_subscription_price WHERE id = ?', [$offerId]));
    }

    /**
     * P124: the subscription ends first — the offer is void, also an agreed
     * one not yet billed. An end after the effective day leaves it standing.
     */
    public function testVerfaelltWennDasAboVorherEndet(): void
    {
        $offerId = $this->sentOffer();
        self::assertNull($this->offers()->void($offerId, $this->leadDay + 60));

        $this->connection->update('tl_consho_subscription', ['endsAt' => $this->end + 86_400 * 31], ['id' => 1]);
        self::assertNull($this->offers()->void($offerId, $this->leadDay + 60));

        $this->connection->update('tl_consho_subscription', ['endsAt' => $this->end], ['id' => 1]);
        $this->connection->update('tl_consho_subscription_price', ['state' => 'accepted', 'answeredBy' => 'account'], ['id' => $offerId]);
        self::assertSame('WEB-00001-1', $this->offers()->void($offerId, $this->leadDay + 60));
        self::assertSame('void', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription_price WHERE id = ?', [$offerId]));

        // Settled offers stay as they are.
        self::assertNull($this->offers()->void($offerId, $this->leadDay + 120));
        self::assertNull($this->offers()->lapse($offerId, self::at('2026-12-16 23:59:59')));

        // The subscription deleted: named by its former id.
        $this->connection->update('tl_consho_subscription_price', ['state' => 'open'], ['id' => $offerId]);
        $this->connection->delete('tl_consho_subscription', ['id' => 1]);
        self::assertSame('ID 1', $this->offers()->void($offerId, $this->leadDay + 120));
    }

    /**
     * P119, P120: agreed until the deadline, final.
     */
    public function testZustimmungBisZurFristUndEndgueltig(): void
    {
        $offerId = $this->sentOffer();
        $deadline = self::at('2026-12-16 23:59:59');

        self::assertNull($this->offers()->answer($offerId, 2, true, 'Jane', $this->leadDay + 60), 'only for its own subscription');

        $answered = $this->offers()->answer($offerId, 1, true, 'Jane', $deadline - 60);
        self::assertNotNull($answered);
        self::assertTrue($answered->accepted);
        self::assertSame('accepted', $answered->offer->event);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription_price WHERE id = ?', [$offerId]);
        self::assertIsArray($row);
        self::assertSame(['accepted', 'account', $deadline - 60], [$row['state'], $row['answeredBy'], (int) $row['answeredAt']]);

        self::assertNull($this->offers()->answer($offerId, 1, false, 'Jane', $deadline - 30), 'final');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_cancellation'));
    }

    /**
     * P121: declined, the subscription ends at the effective day.
     */
    public function testAblehnungKuendigtZumGueltigkeitstag(): void
    {
        $offerId = $this->sentOffer();

        $answered = $this->offers()->answer($offerId, 1, false, 'Jane Doe', $this->leadDay + 60);
        self::assertNotNull($answered);
        self::assertFalse($answered->accepted);
        self::assertNotNull($answered->cancellation);
        self::assertSame('WEB-00001-1-K1', $answered->cancellation->number);

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription_price WHERE id = ?', [$offerId]);
        self::assertIsArray($row);
        self::assertSame(['declined', $answered->cancellation->id], [$row['state'], (int) $row['cancellation']]);
        self::assertSame('Jane Doe', $this->connection->fetchOne('SELECT name FROM tl_consho_cancellation WHERE id = ?', [$answered->cancellation->id]));
        self::assertSame($this->end, (int) $this->connection->fetchOne('SELECT endsAt FROM tl_consho_subscription WHERE id = 1'));
    }

    public function testNachDerFristKeineAntwortMehr(): void
    {
        $offerId = $this->sentOffer();

        self::assertNull($this->offers()->answer($offerId, 1, true, 'Jane', self::at('2026-12-16 23:59:59')));
        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_subscription_price WHERE id = ?', [$offerId]));
    }

    private function offers(): SubscriptionPriceOffers
    {
        $allows = $this->createStub(SubscriptionOffers::class);
        $allows->method('allows')->willReturn(true);

        $prices = $this->createStub(CartLinePriceSource::class);
        $prices->method('forCartLine')->willReturnCallback(fn (): CartLinePrice|null => null === $this->price ? null : new CartLinePrice($this->price, 1));

        $articles = $this->createStub(ArticleTreeInterface::class);
        $articles->method('articlesOf')->willReturn([40 => 4]);

        $calendar = new SubscriptionCalendar(self::ZONE);
        $check = new SubscriptionPriceCheck($this->connection, $calendar, new OperatorCancellationDates($calendar), $allows, $prices, $articles);

        $lock = new class($this->connection) implements SubscriptionLockInterface {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function lock(int $subscriptionId): array|null
            {
                $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$subscriptionId]);

                return false === $row ? null : $row;
            }
        };

        $recorder = new CancellationRecorder(
            $this->connection,
            $lock,
            new CancellationTerm($calendar),
            $this->createStub(OrderEventMail::class),
            $this->createStub(OrderPageLink::class),
            new SubscriptionContact($this->connection),
        );

        return new SubscriptionPriceOffers($this->connection, $lock, $check, $recorder);
    }

    /**
     * An offer as the run leaves it after its mail.
     */
    private function sentOffer(): int
    {
        $due = $this->offers()->prepare(1, $this->leadDay);
        self::assertNotNull($due);
        $this->offers()->sent($due->offerId, $this->leadDay);

        return $due->offerId;
    }

    private static function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone(self::ZONE)))->getTimestamp();
    }
}
