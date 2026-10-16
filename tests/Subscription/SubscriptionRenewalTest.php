<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartPricer;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticleTreeInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\CartLinePriceSource;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalContext;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionCalendar;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionDelinquency;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLockInterface;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Tax\TaxFixture;

/**
 * Abo-7b (P66, P67): welches Abo „Lizenz verlängern" anbietet, und zu welchem
 * Preis.
 */
final class SubscriptionRenewalTest extends TestCase
{
    use TaxFixture;

    private const NOW = 1_790_000_000;

    private Connection $connection;

    /** @var array<int, string> what each order still owes, by id */
    private array $outstanding = [];

    /** @var array<int, string|null> net per licence by rule; null = no price */
    private array $net = [5 => '1.0000'];

    private bool $offered = true;

    /** @var list<array{int, int, list<int>}> the questions put to SubscriptionOffers::allows() */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_subscription', 'tl_consho_entitlement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'grandTotal' => '2.4000']);
        $this->outstanding = [90 => '0'];
    }

    public function testDasEndeEinesAbos(): void
    {
        self::assertSame(500, SubscriptionRenewal::endOf(['endsAt' => 500, 'renewal' => 'indefinite', 'minimumEndsAt' => 900]), 'gekündigt');
        self::assertSame(900, SubscriptionRenewal::endOf(['endsAt' => null, 'renewal' => 'ends', 'minimumEndsAt' => 900]), 'Endet');
        self::assertSame(0, SubscriptionRenewal::endOf(['endsAt' => null, 'renewal' => 'indefinite', 'minimumEndsAt' => 900]), 'läuft weiter');
        self::assertSame(0, SubscriptionRenewal::endOf(['endsAt' => 0, 'renewal' => 'sameTerm', 'minimumEndsAt' => 900]), 'verlängert sich');
    }

    /**
     * P68 mit P55: Die Lizenz endet mit der Periode, die das Vertragsende
     * enthält — dort beginnt die Verlängerung, nicht am Vertragsende selbst.
     * Monatsabo ab 20.12.2025 08:00.
     */
    public function testDieLizenzEndetMitDerPeriodeUmDasVertragsende(): void
    {
        $calendar = new SubscriptionCalendar('Europe/Vienna');
        $monthly = ['renewal' => 'indefinite', 'billingInterval' => 1, 'termLength' => 1, 'termUnit' => 'year', 'startedAt' => $this->at('2025-12-20 08:00'), 'minimumEndsAt' => $this->at('2026-12-20 08:00')];

        // Gekündigt zum 25.02., abgerechnet bis 20.02. → die Periode bis 20.03. kommt noch, voll (P55).
        self::assertSame($this->at('2026-03-20 08:00'), SubscriptionRenewal::licenceEndOf(['endsAt' => $this->at('2026-02-25 12:00'), 'periodUntil' => $this->at('2026-02-20 08:00')] + $monthly, $calendar));

        // Gekündigt genau zur Grenze: dort.
        self::assertSame($this->at('2026-02-20 08:00'), SubscriptionRenewal::licenceEndOf(['endsAt' => $this->at('2026-02-20 08:00'), 'periodUntil' => $this->at('2026-01-20 08:00')] + $monthly, $calendar));

        // Außerordentlich mitten in einer schon bezahlten Periode: bis zu deren Ende.
        self::assertSame($this->at('2026-03-20 08:00'), SubscriptionRenewal::licenceEndOf(['endsAt' => $this->at('2026-03-01 00:00'), 'periodUntil' => $this->at('2026-03-20 08:00')] + $monthly, $calendar));

        // Läuft weiter: kein Ende.
        self::assertSame(0, SubscriptionRenewal::licenceEndOf(['endsAt' => null, 'periodUntil' => $this->at('2026-03-20 08:00')] + $monthly, $calendar));
    }

    public function testEndetMitEinerZahlungJeLaufzeit(): void
    {
        // Jahresabo, eine Zahlung, *Endet*: die Laufzeit ist die Periode.
        $row = ['renewal' => 'ends', 'billingInterval' => 0, 'termLength' => 12, 'termUnit' => 'month', 'startedAt' => $this->at('2025-03-01 09:00'),
            'minimumEndsAt' => $this->at('2026-03-01 09:00'), 'periodUntil' => $this->at('2026-03-01 09:00'), 'endsAt' => null];

        self::assertSame($this->at('2026-03-01 09:00'), SubscriptionRenewal::licenceEndOf($row, new SubscriptionCalendar('Europe/Vienna')));
    }

    public function testNurEinEndendesAboIstVerlaengerbar(): void
    {
        $renewal = $this->renewal();

        self::assertTrue($renewal->renewable($this->subscription(['endsAt' => self::NOW + 86_400]), self::NOW), 'gekündigt');
        self::assertTrue($renewal->renewable($this->subscription(['renewal' => 'ends']), self::NOW), 'Endet');
        self::assertTrue($renewal->renewable($this->subscription(['state' => 'ended', 'endsAt' => self::NOW - 86_400]), self::NOW), 'beendet');
        self::assertFalse($renewal->renewable($this->subscription(), self::NOW), 'unbefristet, ungekündigt');
    }

    public function testRuhendSchonVerlaengertOderUeberfaelligNicht(): void
    {
        $renewal = $this->renewal();

        self::assertFalse($renewal->renewable($this->subscription(['state' => 'suspended', 'endsAt' => self::NOW + 86_400]), self::NOW), 'ruht');
        self::assertFalse($renewal->renewable($this->subscription(['endsAt' => self::NOW + 86_400, 'renewedBy' => 4]), self::NOW), 'schon verlängert');
        self::assertFalse($renewal->renewable($this->subscription(['state' => 'paused', 'endsAt' => self::NOW + 86_400]), self::NOW), 'pausiert (P106)');

        // Beendet, aber die Berechtigung ruht noch (P64): eine offene Rechnung.
        $this->connection->insert('tl_consho_entitlement', ['id' => 30, 'pid' => 90, 'subscription' => 1, 'suspendedAt' => self::NOW - 10]);
        self::assertFalse($renewal->renewable($this->subscription(['state' => 'ended', 'endsAt' => self::NOW - 86_400]), self::NOW), 'Berechtigung ruht');
    }

    public function testEinAboMitStornierterKaufbestellungIstNichtVerlaengerbar(): void
    {
        // Abnahme 2026-10-01: die zurückgebaute Verlängerung WEB-00091-1.
        $row = $this->subscription(['state' => 'ended', 'endsAt' => self::NOW - 60]);
        self::assertTrue($this->renewal()->renewable($row, self::NOW), 'Gegenprobe');

        $this->connection->update('tl_consho_order', ['cancelledAt' => self::NOW - 60], ['id' => 90]);
        self::assertFalse($this->renewal()->renewable($row, self::NOW));
    }

    public function testUeberfaelligZaehltSchonVorDerNachfrist(): void
    {
        // Folgebestellung, Ziel gestern, offen — die Nachfrist (14 Tage) läuft
        // noch, das Abo ruht also nicht. Verlängert wird trotzdem nicht.
        $this->connection->insert('tl_consho_order', ['id' => 91, 'orderNumber' => 'WEB-00091', 'subscription' => 1, 'grandTotal' => '1.2000', 'paymentDueAt' => self::NOW - 86_400]);
        $this->outstanding[91] = '1.2000';

        self::assertFalse($this->renewal()->renewable($this->subscription(['endsAt' => self::NOW + 86_400]), self::NOW));

        $this->outstanding[91] = '0';
        self::assertTrue($this->renewal()->renewable($this->subscription(['endsAt' => self::NOW + 86_400]), self::NOW), 'Gegenprobe: bezahlt');
    }

    public function testDieKasseFragtKontoShopArtikelUndVerlaengerbar(): void
    {
        $this->connection->insert('tl_consho_subscription', ['member' => 5, 'shop' => 2, 'product' => 7, 'state' => 'active', 'renewal' => 'indefinite'] + $this->subscription(['endsAt' => self::NOW + 86_400]));
        $renewal = $this->renewal();

        self::assertTrue($renewal->allowsPurchase(1, 5, 2, 7, self::NOW));
        self::assertFalse($renewal->allowsPurchase(1, 6, 2, 7, self::NOW), 'fremdes Konto');
        self::assertFalse($renewal->allowsPurchase(1, 0, 2, 7, self::NOW), 'Gast');
        self::assertFalse($renewal->allowsPurchase(1, 5, 3, 7, self::NOW), 'anderer Shop');
        self::assertFalse($renewal->allowsPurchase(1, 5, 2, 8, self::NOW), 'anderer Artikel');
        self::assertFalse($renewal->allowsPurchase(99, 5, 2, 7, self::NOW), 'gibt es nicht');

        $this->connection->update('tl_consho_subscription', ['renewedBy' => 4], ['id' => 1]);
        self::assertFalse($renewal->allowsPurchase(1, 5, 2, 7, self::NOW), 'inzwischen verlängert');
    }

    public function testDieNummernDerVerlaengertenAbos(): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => 1, 'subscriptionNumber' => 'WEB-00090-1']);
        $this->connection->insert('tl_consho_subscription', ['id' => 2, 'subscriptionNumber' => 'WEB-00091-1']);

        self::assertSame([2 => 'WEB-00091-1'], $this->renewal()->numbersOf([0, 2, 2]));
        self::assertSame([], $this->renewal()->numbersOf([0]));
    }

    public function testDasAngebotNenntDenHeutigenPreisFuerDieAlteMenge(): void
    {
        // Eingefroren 0,50 netto; heute kostet das Modell 1,00 netto, 20 %.
        $offer = $this->renewal()->offer($this->subscription(['endsAt' => self::NOW + 86_400, 'unitNet' => '0.5000', 'quantity' => '2.0000']), $this->context(), 'de', self::NOW);

        self::assertNotNull($offer);
        self::assertSame([1, 7, 5, '2.0000'], [$offer->subscriptionId, $offer->articleId, $offer->ruleId, $offer->quantity]);
        // 1,00 × 2 = 2,00 netto → 2,40 brutto.
        self::assertSame('Regel 5 zu 2.4000', $offer->terms);
        // Gefragt mit den Gruppen, die der Kunde HEUTE hat, nicht nach dem
        // eingefrorenen Stand des alten Vertrags (Review S2).
        self::assertSame([[7, 5, [3]]], $this->asked);
    }

    public function testNettoKundenLesenNetto(): void
    {
        $offer = $this->renewal()->offer($this->subscription(['quantity' => '2.0000', 'businessCustomer' => 0]), $this->context(showsNet: true), 'de', self::NOW);

        self::assertSame('Regel 5 zu 2.0000', $offer?->terms);
        self::assertSame([[7, 5, [3]]], $this->asked);
    }

    public function testNichtAnbietenWasEsNichtGibt(): void
    {
        $row = $this->subscription(['endsAt' => self::NOW + 86_400]);

        $this->offered = false;
        self::assertNull($this->renewal()->offer($row, $this->context(), 'de', self::NOW), 'Modell nicht mehr angeboten');

        $this->offered = true;
        $this->net[5] = null;
        self::assertNull($this->renewal()->offer($row, $this->context(), 'de', self::NOW), 'kein Preis');

        $this->net[5] = '1.0000';
        self::assertNull($this->renewal()->offer($row, $this->context(currency: 'CHF'), 'de', self::NOW), 'andere Währung');
        self::assertNull($this->renewal()->offer($row, $this->context(cartUrl: ''), 'de', self::NOW), 'keine Warenkorbseite');
        self::assertNotNull($this->renewal()->offer($row, $this->context(), 'de', self::NOW), 'Gegenprobe');
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function subscription(array $values = []): array
    {
        return [
            'id' => 1,
            'subscriptionNumber' => 'WEB-00090-1',
            'firstOrder' => 90,
            'product' => 7,
            'rule' => 5,
            'quantity' => '1.0000',
            'unitNet' => '1.0000',
            'currency' => 'EUR',
            'businessCustomer' => 1,
            'renewal' => 'indefinite',
            'minimumEndsAt' => self::NOW + 400 * 86_400,
            'state' => 'active',
            'endsAt' => null,
            'renewedBy' => 0,
            'graceDays' => 14,
            ...$values,
        ];
    }

    private function at(string $local): int
    {
        return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/Vienna')))->getTimestamp();
    }

    private function context(string $currency = 'EUR', bool $showsNet = false, string $cartUrl = 'https://shop.test/warenkorb'): RenewalContext
    {
        return new RenewalContext($currency, 'AT', [3], $showsNet, $cartUrl);
    }

    private function renewal(): SubscriptionRenewal
    {
        $prices = new class($this->net) implements CartLinePriceSource {
            /** @param array<int, string|null> $net */
            public function __construct(private readonly array $net)
            {
            }

            public function forCartLine(int $articleId, int $mainProductId, string $currency, string $quantity, int $now, array $customerGroups = [], int $subscriptionRule = 0): CartLinePrice|null
            {
                $net = $this->net[$subscriptionRule] ?? null;

                return null === $net ? null : new CartLinePrice($net, 1);
            }
        };

        $tree = $this->createStub(ArticleTreeInterface::class);
        $tree->method('articlesOf')->willReturnCallback(static fn (array $ids): array => array_combine($ids, $ids));

        $offers = $this->createStub(SubscriptionOffers::class);
        $offers->method('allows')->willReturnCallback(function (int $articleId, int $ruleId, array $groups): bool {
            $this->asked[] = [$articleId, $ruleId, $groups];

            return $this->offered;
        });

        $terms = $this->createStub(SubscriptionLineTerms::class);
        $terms->method('of')->willReturnCallback(static fn (int $ruleId, string $amount): string => \sprintf('Regel %d zu %s', $ruleId, $amount));

        $lock = $this->createStub(SubscriptionLockInterface::class);
        $balances = $this->createStub(OrderBalanceReaderInterface::class);
        $balances->method('of')->willReturnCallback(fn (int $orderId): OrderBalance => new OrderBalance(
            $this->outstanding[$orderId] ?? '0',
            '0',
            PaymentState::Open,
            DeliveryState::Complete,
            [],
            true,
        ));

        return new SubscriptionRenewal(
            $this->connection,
            new CartPricer($tree, $prices, $this->grossCalculator()),
            $offers,
            $terms,
            new SubscriptionDelinquency($this->connection, $lock, $balances, new SubscriptionCalendar('Europe/Vienna')),
        );
    }
}
