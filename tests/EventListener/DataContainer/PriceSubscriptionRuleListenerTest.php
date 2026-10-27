<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PriceSubscriptionRuleListener;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\SaleModes;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-1 (A4): the price row only offers what the product sells.
 *
 * Product 1: digital, subscription only, with rules 5 and 9 (9 deleted).
 * Product 2: variant of 1. Product 3: digital, one-off purchase and rule 5.
 */
class PriceSubscriptionRuleListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_product');
        DcaSchema::create($this->connection, 'tl_consho_product_type');
        DcaSchema::create($this->connection, 'tl_consho_subscription_rule');
        DcaSchema::create($this->connection, 'tl_consho_price');

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'kind' => 'digital']);
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 5, 'title' => 'Monatsabo']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'productType' => 1, 'saleOnce' => 0, 'saleSubscription' => 1, 'subscriptionRules' => serialize(['5', '9'])]);
        $this->connection->insert('tl_consho_product', ['id' => 2, 'pid' => 1]);
        $this->connection->insert('tl_consho_product', ['id' => 3, 'productType' => 1, 'saleOnce' => 1, 'saleSubscription' => 1, 'subscriptionRules' => serialize(['5'])]);

        // Price row 10 on product 1, 20 on variant 2, 30 on product 3.
        $this->connection->insert('tl_consho_price', ['id' => 10, 'pid' => 1]);
        $this->connection->insert('tl_consho_price', ['id' => 20, 'pid' => 2]);
        $this->connection->insert('tl_consho_price', ['id' => 30, 'pid' => 3]);

        $GLOBALS['TL_LANG']['tl_consho_price']['oneTimeSale'] = 'Einmalkauf';
        $GLOBALS['TL_LANG']['tl_consho_price']['ruleNotOffered'] = 'nicht angeboten';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testNurImAbo(): void
    {
        // Without one-off purchase, the deleted rule 9 drops out.
        self::assertSame([5 => 'Monatsabo'], $this->listener()->options($this->dc(10)));
    }

    public function testDieVarianteWaehltAusDenRegelnDesGrundprodukts(): void
    {
        self::assertSame([5 => 'Monatsabo'], $this->listener()->options($this->dc(20)));
    }

    public function testEinmalkaufZuerst(): void
    {
        self::assertSame([0 => 'Einmalkauf', 5 => 'Monatsabo'], $this->listener()->options($this->dc(30)));
    }

    public function testEinNichtAngebotenerWertWirdNichtGespeichert(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('nicht angeboten');

        // One-off purchase on a product that is only sold by subscription.
        $this->listener()->refuseNotOffered('0', $this->dc(10));
    }

    public function testEineBeschriftungAlsWertWirdNichtZumEinmalkauf(): void
    {
        /*
         * The case this guards: the form sent „Monatsabo" instead of 5, and
         * (int) turned that into 0 — product 3 offers the one-off purchase,
         * so a subscription price was saved as a one-off purchase.
         */
        $this->expectException(\RuntimeException::class);

        $this->listener()->refuseNotOffered('Monatsabo', $this->dc(30));
    }

    public function testEinAngebotenerWertKommtUnveraendertZurueck(): void
    {
        self::assertSame('5', $this->listener()->refuseNotOffered('5', $this->dc(10)));
    }

    public function testDieZeileNenntIhreRegel(): void
    {
        $listener = $this->listener();
        $row = ['currency' => 'EUR', 'net' => '10.0000', 'minQuantity' => '1.0000'];
        $plain = $listener->label(['subscriptionRule' => '0'] + $row, 'roh');

        self::assertSame('<strong>Monatsabo</strong> · '.$plain, $listener->label(['subscriptionRule' => '5'] + $row, 'roh'));
    }

    /**
     * WG: the price list showed „EUR 25.0000 netto ab 1.0000" —
     * decimal(10,4) raw from the database.
     */
    public function testBetragUndMengeSindFormatiert(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';

        try {
            $label = $this->listener()->label(['subscriptionRule' => '0', 'currency' => 'EUR', 'net' => '25.0000', 'minQuantity' => '1.0000'], 'roh');
        } finally {
            unset($GLOBALS['TL_LANGUAGE']);
        }

        $money = new MoneyFormatter();

        self::assertSame($money->format('25.0000', 'EUR', 'de').' netto <span class="tl_gray">ab 1</span>', $label);
        self::assertStringNotContainsString('.0000', $label);
    }

    public function testDerRegeltitelWirdMaskiert(): void
    {
        $this->connection->update('tl_consho_subscription_rule', ['title' => '<b>Abo</b>'], ['id' => 5]);

        self::assertStringStartsWith('<strong>&lt;b&gt;Abo&lt;/b&gt;</strong> · ', $this->listener()->label(['subscriptionRule' => 5, 'currency' => 'EUR', 'net' => '1', 'minQuantity' => '1'], 'x'));
    }

    private function listener(): PriceSubscriptionRuleListener
    {
        return new PriceSubscriptionRuleListener($this->connection, new SaleModes($this->connection), new MoneyFormatter());
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}
