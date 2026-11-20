<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionAgreedPrice;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-10 (P120, P135): which agreed price a period bills, and the amount its
 * conditions text is built from.
 *
 * Subscription 1: 2 units at 4.1667 net, bought in order 90 whose product line
 * is taxed at 20 %.
 */
class SubscriptionAgreedPriceTest extends TestCase
{
    private Connection $connection;

    /** @var list<string> the amounts the conditions text was asked for */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_subscription', 'tl_consho_subscription_price'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 90, 'orderNumber' => 'WEB-00090', 'createdAt' => 1_000]);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 90, 'lineType' => 'product', 'taxRate' => '20.00']);
        $this->connection->insert('tl_consho_subscription', [
            'id' => 1, 'subscriptionNumber' => 'WEB-00090-1', 'firstOrder' => 90, 'orderItem' => 2, 'rule' => 1,
            'quantity' => '2.0000', 'unitNet' => '4.1667', 'currency' => 'EUR', 'terms' => 'alt',
        ]);
    }

    /**
     * The newest effective one wins — not one still open, not one whose mail
     * never went out, not one effective later, even where they are newer.
     */
    public function testNurDasNeuesteGueltigeVersandteZaehlt(): void
    {
        $this->offer(['id' => 1, 'effectiveAt' => 100, 'newUnitNet' => '5.0000']);
        $this->offer(['id' => 2, 'effectiveAt' => 200, 'newUnitNet' => '6.0000']);
        $this->offer(['id' => 3, 'effectiveAt' => 250, 'newUnitNet' => '7.0000', 'state' => 'open']);
        $this->offer(['id' => 4, 'effectiveAt' => 260, 'newUnitNet' => '8.0000', 'mailSentAt' => 0]);
        $this->offer(['id' => 5, 'effectiveAt' => 400, 'newUnitNet' => '9.0000']);
        $this->offer(['id' => 6, 'effectiveAt' => 280, 'newUnitNet' => '3.0000', 'appliedAt' => 50]);

        $agreed = $this->prices()->agreed(['id' => 1], 300);
        self::assertNotNull($agreed);
        self::assertSame([2, 6.0, 200], [$agreed['id'], (float) $agreed['newUnitNet'], $agreed['effectiveAt']]);
        self::assertNull($this->prices()->agreed(['id' => 1], 99));
        self::assertNull($this->prices()->agreed(['id' => 2], 300));
    }

    /**
     * As OrderFactory froze it at the purchase: gross for a consumer and for
     * a business customer taxed at home, net only with reverse charge.
     */
    public function testDerBedingungstextIstBruttoAusserBeiReverseCharge(): void
    {
        $this->offer(['id' => 1, 'effectiveAt' => 100, 'newUnitNet' => '5.0000']);

        $due = $this->prices()->due($this->subscription(['reverseCharge' => 0, 'businessCustomer' => 0]), 100, 'de');
        self::assertNotNull($due);
        self::assertSame([1, 5.0, 'neu'], [$due['id'], (float) $due['unitNet'], $due['terms']]);

        $this->prices()->due($this->subscription(['reverseCharge' => 0, 'businessCustomer' => 1]), 100, 'de');
        $this->prices()->due($this->subscription(['reverseCharge' => 1, 'businessCustomer' => 1]), 100, 'de');

        self::assertSame([12.0, 12.0, 10.0], array_map('floatval', $this->asked));
    }

    /**
     * Applied marks every accepted, mailed offer up to the period — not an
     * open one, not one whose mail never went out, not a later one.
     */
    public function testUebernehmenMarkiertNurWasMitGilt(): void
    {
        $this->offer(['id' => 1, 'effectiveAt' => 100]);
        $this->offer(['id' => 2, 'effectiveAt' => 200]);
        $this->offer(['id' => 3, 'effectiveAt' => 150, 'state' => 'open']);
        $this->offer(['id' => 4, 'effectiveAt' => 180, 'mailSentAt' => 0]);
        $this->offer(['id' => 5, 'effectiveAt' => 300]);

        $this->prices()->apply($this->subscription([]), ['id' => 2, 'unitNet' => '6.0000', 'terms' => 'neu'], 200, 999);

        self::assertSame([1 => 999, 2 => 999, 3 => 0, 4 => 0, 5 => 0], array_map('intval', $this->connection->fetchAllKeyValue('SELECT id, appliedAt FROM tl_consho_subscription_price ORDER BY id')));
        $row = $this->connection->fetchAssociative('SELECT unitNet, terms FROM tl_consho_subscription WHERE id = 1') ?: [];
        self::assertSame([6.0, 'neu'], [(float) $row['unitNet'], $row['terms']]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function offer(array $values): void
    {
        $this->connection->insert('tl_consho_subscription_price', $values + [
            'pid' => 1, 'oldUnitNet' => '4.1667', 'newUnitNet' => '5.0000', 'currency' => 'EUR', 'quantity' => '2.0000',
            'state' => 'accepted', 'answeredBy' => 'account', 'mailSentAt' => 10,
        ]);
    }

    /**
     * Subscription 1 as the table holds it, with these columns set first —
     * a renamed column fails here instead of passing as a made-up key.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private function subscription(array $values): array
    {
        if ([] !== $values) {
            $this->connection->update('tl_consho_subscription', $values, ['id' => 1]);
        }

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = 1');
        self::assertIsArray($row);

        return $row;
    }

    private function prices(): SubscriptionAgreedPrice
    {
        $lineTerms = $this->createStub(SubscriptionLineTerms::class);
        $lineTerms->method('of')->willReturnCallback(function (int $rule, string $amount): string {
            $this->asked[] = $amount;

            return 'neu';
        });

        return new SubscriptionAgreedPrice($this->connection, $lineTerms);
    }
}
