<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Subscription\LifecycleChange;
use Webwerkwien\ContaoConshoBundle\Subscription\ReminderDue;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycle;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLifecycleRun;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-6: WHOM the run asks and which reminder it makes of it. The verdict
 * itself is checked by SubscriptionLifecycleTest.
 */
final class SubscriptionLifecycleRunTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86_400;

    private Connection $connection;

    /** @var list<string> */
    private array $asked = [];

    /** @var array<int, ReminderDue|null> */
    private array $reminders = [];

    /** @var list<array<string, mixed>> */
    private array $mails = [];

    private bool $mailLeaves = true;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_subscription', 'tl_consho_order', 'tl_consho_order_item', 'tl_consho_product'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'language' => 'de', 'createdAt' => 1]);
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'language' => 'de', 'subscription' => 1, 'createdAt' => 2]);
        $this->connection->insert('tl_consho_order_item', ['id' => 5, 'pid' => 90, 'productName' => 'E-Book Kräuterkunde']);

        $base = ['firstOrder' => 90, 'orderItem' => 5, 'product' => 4, 'quantity' => '2.0000', 'termLength' => 1, 'termUnit' => 'year', 'noticeDays' => 14, 'reminderDays' => 30, 'renewal' => 'indefinite', 'billingInterval' => 0];

        // Reminder due in 20 days → candidate.
        $this->subscription(1, ['minimumEndsAt' => self::NOW + 20 * self::DAY] + $base);
        // Already reminded, too early, cancelled, without lead time, ended → no candidates.
        $this->subscription(2, ['minimumEndsAt' => self::NOW + 20 * self::DAY, 'reminderSentAt' => 5] + $base);
        $this->subscription(3, ['minimumEndsAt' => self::NOW + 60 * self::DAY] + $base);
        $this->subscription(4, ['minimumEndsAt' => self::NOW + 20 * self::DAY, 'endsAt' => self::NOW + 20 * self::DAY] + $base);
        $this->subscription(5, ['minimumEndsAt' => self::NOW + 20 * self::DAY, 'reminderDays' => 0] + $base);
        $this->subscription(6, ['minimumEndsAt' => self::NOW + 20 * self::DAY, 'state' => 'ended'] + $base);
        // Abo-7b: already renewed → no candidate.
        $this->subscription(11, ['minimumEndsAt' => self::NOW + 20 * self::DAY, 'renewedBy' => 1] + $base);
        // End of the minimum term reached → transition; a known end in open-ended too.
        $this->subscription(7, ['minimumEndsAt' => self::NOW - 60] + $base);
        $this->subscription(8, ['minimumEndsAt' => self::NOW - 400 * self::DAY, 'phase' => 'indefinite', 'endsAt' => self::NOW - 60] + $base);
        // Open-ended without an end, ended → no transition.
        $this->subscription(9, ['minimumEndsAt' => self::NOW - 400 * self::DAY, 'phase' => 'indefinite'] + $base);
        $this->subscription(10, ['minimumEndsAt' => self::NOW - 60, 'state' => 'ended', 'endsAt' => self::NOW - 60] + $base);
        // Pausing a subscription (P106): paused → reminder and transition as when active;
        // the pause has moved the end, after all, but it still comes.
        $this->subscription(12, ['minimumEndsAt' => self::NOW + 20 * self::DAY, 'state' => 'paused'] + $base);
        $this->subscription(13, ['minimumEndsAt' => self::NOW - 60, 'state' => 'paused'] + $base);
    }

    public function testNurFaelligeErinnerungen(): void
    {
        $this->lifecycleRun()->remind(self::NOW);

        self::assertSame(['remind 1', 'remind 12'], $this->asked);
    }

    public function testNurFaelligeUebergaenge(): void
    {
        $changes = $this->lifecycleRun()->transition(self::NOW);

        self::assertSame(['transition 7', 'transition 8', 'transition 13'], $this->asked);
        self::assertSame(['WEB-00007-1' => LifecycleChange::OpenEnded, 'WEB-00008-1' => LifecycleChange::OpenEnded, 'WEB-00013-1' => LifecycleChange::OpenEnded], $changes);
    }

    /**
     * The mail goes to the newest order (address, language). Variant A:
     * it no longer names a monthly amount — a legacy rule without monthly
     * payment ends.
     */
    public function testDieErinnerungGehtAnDieNeuesteBestellung(): void
    {
        $this->reminders[1] = new ReminderDue(1, 'WEB-00001-1', RenewalKind::Indefinite, self::NOW + 20 * self::DAY, self::NOW + 6 * self::DAY, true);

        $this->lifecycleRun()->remind(self::NOW);

        self::assertCount(1, $this->mails);
        self::assertSame(91, $this->mails[0]['order']);
        self::assertSame('endsNoMonthly', $this->mails[0]['kind']);
        self::assertArrayNotHasKey('monthly', $this->mails[0]);
        self::assertSame('E-Book Kräuterkunde', $this->mails[0]['product']);
        self::assertSame([1, 'year', 14], [$this->mails[0]['termLength'], $this->mails[0]['termUnit'], $this->mails[0]['noticeDays']]);
    }

    /**
     * Abo-6: before a renewal the reminder is the
     * notice under § 6 para. 1 no. 2 KSchG. If it did not go out, it does
     * not count as sent — the next hour tries again.
     */
    public function testEineNichtVerschickteErinnerungWirdZurueckgesetzt(): void
    {
        $this->reminders[1] = new ReminderDue(1, 'WEB-00001-1', RenewalKind::Indefinite, self::NOW + 20 * self::DAY, self::NOW + 6 * self::DAY);
        $this->mailLeaves = false;

        self::assertSame([], $this->lifecycleRun()->remind(self::NOW));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT reminderSentAt FROM tl_consho_subscription WHERE id = 1'));
    }

    public function testEineVerschickteErinnerungBleibtMarkiert(): void
    {
        $this->reminders[1] = new ReminderDue(1, 'WEB-00001-1', RenewalKind::Indefinite, self::NOW + 20 * self::DAY, self::NOW + 6 * self::DAY);

        self::assertCount(1, $this->lifecycleRun()->remind(self::NOW));
        self::assertSame(self::NOW, (int) $this->connection->fetchOne('SELECT reminderSentAt FROM tl_consho_subscription WHERE id = 1'));
    }

    /**
     * @return iterable<string, array{RenewalKind, int, bool, string}>
     */
    public static function fassungen(): iterable
    {
        yield 'endet' => [RenewalKind::Ends, 0, false, 'ends'];
        yield 'gleiche Laufzeit' => [RenewalKind::SameTerm, 0, false, 'sameTerm'];
        yield 'Monatsabo unbefristet' => [RenewalKind::Indefinite, 1, false, 'indefinite'];
        yield 'Altregel ohne monatliche Zahlung' => [RenewalKind::Indefinite, 0, true, 'endsNoMonthly'];
    }

    /**
     * @dataProvider fassungen
     */
    public function testDieFassungDerErinnerung(RenewalKind $renewal, int $interval, bool $noMonthly, string $kind): void
    {
        $this->connection->update('tl_consho_subscription', ['billingInterval' => $interval], ['id' => 1]);
        $this->reminders[1] = new ReminderDue(1, 'WEB-00001-1', $renewal, self::NOW + 20 * self::DAY, self::NOW + 6 * self::DAY, $noMonthly);

        $this->lifecycleRun()->remind(self::NOW);

        self::assertSame($kind, $this->mails[0]['kind']);
    }

    /**
     * Abo-7b (P71): "My subscriptions" from the root page of the order language — the
     * page the operator names in `consho_subscriptionsPage`; without it
     * null, and the mail links the product page.
     */
    public function testDieErinnerungFindetMeineAbos(): void
    {
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'consho_subscriptionsPage' => 77]);
        $this->reminders[1] = new ReminderDue(1, 'WEB-00001-1', RenewalKind::Ends, self::NOW + 20 * self::DAY, self::NOW + 6 * self::DAY, false);

        $this->lifecycleRun($this->pageUrls())->remind(self::NOW);
        self::assertSame('https://shop.test/seite-77.html', $this->mails[0]['renewUrl']);

        // Without a page on the root page: none.
        $this->connection->update('tl_page', ['consho_subscriptionsPage' => 0], ['id' => 1]);
        $this->connection->update('tl_consho_subscription', ['reminderSentAt' => 0], ['id' => 1]);
        $this->lifecycleRun($this->pageUrls())->remind(self::NOW);
        self::assertNull($this->mails[1]['renewUrl']);
    }

    private function pageUrls(): ShopPageUrls
    {
        $page = $this->createStub(PageModel::class);
        $pageAdapter = $this->createStub(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(static fn (string $method, array $args): PageModel|null => 'findById' === $method && 77 === $args[0] ? $page : null);

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pageAdapter);

        $urls = $this->createStub(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(static fn (object $content): string => $content === $page ? 'https://shop.test/seite-77.html' : '');

        return new ShopPageUrls($framework, new ShopResolver($framework), $urls);
    }

    private function lifecycleRun(ShopPageUrls|null $pageUrls = null): SubscriptionLifecycleRun
    {
        $lifecycle = $this->createMock(SubscriptionLifecycle::class);
        $lifecycle->method('remind')->willReturnCallback(function (int $id, int $now): ReminderDue|null {
            $this->asked[] = 'remind '.$id;

            // As SubscriptionLifecycle::remind() marks it, inside its commit.
            if (isset($this->reminders[$id])) {
                $this->connection->update('tl_consho_subscription', ['reminderSentAt' => $now], ['id' => $id]);
            }

            return $this->reminders[$id] ?? null;
        });
        $lifecycle->method('transition')->willReturnCallback(function (int $id): LifecycleChange {
            $this->asked[] = 'transition '.$id;

            return LifecycleChange::OpenEnded;
        });

        $links = $this->createMock(OrderPageLink::class);
        $links->method('forMail')->willReturn('https://shop.test/bestellung');

        $mail = $this->createMock(OrderEventMail::class);
        $mail->method('subscriptionReminder')->willReturnCallback(function (int $orderId, array $reminder): bool {
            $this->mails[] = ['order' => $orderId] + $reminder;

            return $this->mailLeaves;
        });

        $source = $this->createStub(OrderConfirmationSourceInterface::class);
        $source->method('rootOf')->willReturn(1);

        return new SubscriptionLifecycleRun($this->connection, $lifecycle, $links, $source, $mail, pageUrls: $pageUrls);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function subscription(int $id, array $values): void
    {
        $this->connection->insert('tl_consho_subscription', $values + ['id' => $id, 'subscriptionNumber' => \sprintf('WEB-%05d-1', $id)]);
    }
}
