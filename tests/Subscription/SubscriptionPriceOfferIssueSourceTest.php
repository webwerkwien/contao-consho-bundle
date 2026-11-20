<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\BackendDate;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPriceOfferIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-10 (P129): open price offers as a note, an agreement without its
 * confirmation as a warning until the new price applies.
 */
final class SubscriptionPriceOfferIssueSourceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private const DAY = 86_400;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_shop', 'tl_consho_subscription', 'tl_consho_subscription_price'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Wien DE', 'consho_shop' => 1, 'published' => 1, 'consho_subscriptionsPage' => 2]);
        $this->connection->insert('tl_page', ['id' => 2, 'pid' => 1, 'type' => 'regular', 'title' => 'Meine Abos', 'published' => 1]);

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'shop' => 1, 'subscriptionNumber' => 'WEB-1-1', 'state' => 'active', 'renewal' => 'sameTerm']);
        $this->connection->insert('tl_consho_subscription', ['id' => 2, 'shop' => 1, 'subscriptionNumber' => 'WEB-2-1', 'state' => 'active', 'renewal' => 'sameTerm']);
    }

    public function testOffeneAngeboteMitFrist(): void
    {
        $this->offer(1, 1, ['deadlineAt' => self::NOW + 9 * self::DAY]);
        $this->offer(2, 2, ['deadlineAt' => self::NOW + 3 * self::DAY]);
        // Its mail never left (P135), or settled: no open offer.
        $this->offer(3, 1, ['mailSentAt' => 0, 'effectiveAt' => 2]);
        $this->offer(4, 2, ['state' => 'declined', 'effectiveAt' => 2, 'answerMailSentAt' => 5]);

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::PriceOffersOpen, $issues[0]->finding);
        self::assertFalse($issues[0]->finding->isWarning());
        self::assertSame([
            'shop' => 'Wien',
            'count' => '2',
            'numbers' => 'WEB-2-1 → '.BackendDate::format(self::NOW + 3 * self::DAY).', WEB-1-1 → '.BackendDate::format(self::NOW + 9 * self::DAY),
        ], $issues[0]->context);
        self::assertSame(1, $issues[0]->shopId);
    }

    /**
     * Only an agreement still ahead; a decrease has no answer to confirm, and
     * an ended subscription has nothing left to tell.
     */
    public function testZustimmungOhneBestaetigung(): void
    {
        $this->offer(1, 1, ['state' => 'accepted', 'answeredBy' => 'account']);
        $this->offer(2, 1, ['state' => 'accepted', 'answeredBy' => 'silence', 'effectiveAt' => self::NOW - self::DAY]);
        $this->offer(3, 2, ['state' => 'accepted', 'decrease' => 1, 'effectiveAt' => self::NOW + 2 * self::DAY]);
        $this->offer(4, 2, ['state' => 'accepted', 'answeredBy' => 'account', 'effectiveAt' => self::NOW + 3 * self::DAY, 'answerMailSentAt' => 5]);

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::PriceAgreementNotConfirmed, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['shop' => 'Wien', 'count' => '1', 'numbers' => 'WEB-1-1'], $issues[0]->context);

        $this->connection->update('tl_consho_subscription', ['state' => 'ended'], ['id' => 1]);
        self::assertSame([], $this->source()->issues());
    }

    /**
     * P119: without "Meine Abos" no offer can go out — every published start
     * point of a shop with running subscriptions needs one, published.
     */
    public function testStartpunktOhneMeineAbos(): void
    {
        $before = $this->source()->issues();
        self::assertSame([], $before);

        // Unpublished page, a start point without one; an unpublished start point does not count.
        $this->connection->update('tl_page', ['published' => 0], ['id' => 2]);
        $this->connection->insert('tl_page', ['id' => 3, 'type' => 'root', 'title' => 'Wien EN', 'consho_shop' => 1, 'published' => 1]);
        $this->connection->insert('tl_page', ['id' => 4, 'type' => 'root', 'title' => 'Wien FR', 'consho_shop' => 1, 'published' => 0]);

        $issues = $this->source()->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::SubscriptionsPageMissing, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['shop' => 'Wien', 'roots' => 'Wien DE, Wien EN'], $issues[0]->context);

        // Subscriptions that get no offer — they end, or have ended: nothing to warn of.
        $this->connection->update('tl_consho_subscription', ['renewal' => 'ends'], ['id' => 1]);
        $this->connection->update('tl_consho_subscription', ['endsAt' => self::NOW + self::DAY], ['id' => 2]);
        $none = $this->source()->issues();
        self::assertSame([], $none);

        $this->connection->update('tl_consho_subscription', ['renewal' => 'indefinite', 'billingInterval' => 1], ['id' => 1]);
        $again = $this->source()->issues();
        self::assertCount(1, $again);
        $this->connection->update('tl_consho_subscription', ['state' => 'ended'], []);
        self::assertSame([], $this->source()->issues());
    }

    /**
     * Contao's start/stop windows, of the start point and of the page.
     *
     * @param array<int, array<string, mixed>> $pages
     */
    #[DataProvider('windows')]
    public function testStartUndStopFenster(array $pages, bool $warned): void
    {
        foreach ($pages as $id => $values) {
            $this->connection->update('tl_page', $values, ['id' => $id]);
        }

        self::assertSame($warned ? [ConfigurationFinding::SubscriptionsPageMissing] : [], array_map(static fn ($i) => $i->finding, $this->source()->issues()));
    }

    /**
     * @return iterable<string, array{array<int, array<string, mixed>>, bool}>
     */
    public static function windows(): iterable
    {
        $later = (string) (self::NOW + 3600);
        $earlier = (string) (self::NOW - 3600);

        yield 'page not yet' => [[2 => ['start' => $later]], true];
        yield 'page no longer' => [[2 => ['stop' => $earlier]], true];
        yield 'page in its window' => [[2 => ['start' => $earlier, 'stop' => $later]], false];
        yield 'start point not yet' => [[1 => ['start' => $later, 'consho_subscriptionsPage' => 0]], false];
        yield 'start point no longer' => [[1 => ['stop' => $earlier, 'consho_subscriptionsPage' => 0]], false];
        yield 'start point in its window' => [[1 => ['start' => $earlier, 'stop' => $later, 'consho_subscriptionsPage' => 0]], true];
    }

    private function source(): SubscriptionPriceOfferIssueSource
    {
        return new SubscriptionPriceOfferIssueSource($this->connection, static fn (): int => self::NOW);
    }

    /**
     * An open offer, its mail sent, effective in 30 days.
     *
     * @param array<string, mixed> $values
     */
    private function offer(int $id, int $subscription, array $values): void
    {
        $this->connection->insert('tl_consho_subscription_price', $values + [
            'id' => $id, 'pid' => $subscription, 'state' => 'open', 'mailSentAt' => 1,
            'effectiveAt' => self::NOW + 30 * self::DAY + $id, 'deadlineAt' => self::NOW + 16 * self::DAY,
        ]);
    }
}
