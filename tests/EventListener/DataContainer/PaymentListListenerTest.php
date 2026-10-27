<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PaymentListListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-5: „Gesamtsumme 1.2000" and „1.2000 (Überweisung)" in the payment
 * list — the raw decimal number as MySQL/MariaDB returns a `decimal(10,4)`.
 */
final class PaymentListListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 44, 'orderNumber' => 'WEB-00085', 'currency' => 'EUR', 'grandTotal' => '1.2000']);

        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_CONFIG']['datimFormat'] = 'd.m.Y H:i';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_CONFIG']['datimFormat'], $GLOBALS['TL_DCA']['tl_consho_order']);
    }

    public function testDieZeileZeigtGeld(): void
    {
        $label = (new PaymentListListener($this->connection))->label(['pid' => 44, 'receivedAt' => 0, 'amount' => '1.2000', 'method' => 'Überweisung']);

        self::assertStringNotContainsString('1.2000', $label);
        self::assertStringContainsString('1,20', $label);
        self::assertStringContainsString('€', $label);
        self::assertStringContainsString('(Überweisung)', $label);
    }

    public function testEineErstattungBleibtNegativ(): void
    {
        $label = (new PaymentListListener($this->connection))->label(['pid' => 44, 'receivedAt' => 0, 'amount' => '-1.2000', 'method' => '']);

        self::assertMatchesRegularExpression('/-\s?1,20|−\s?1,20/u', $label);
    }

    public function testDerKopfZeigtDieGesamtsummeAlsGeld(): void
    {
        $GLOBALS['TL_DCA']['tl_consho_order']['fields']['grandTotal']['label'] = ['Gesamtsumme', ''];
        // DataContainer hands out `id` through __get (the parent's id here).
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 44 : null);

        $labels = (new PaymentListListener($this->connection))->header(['Bestellnummer' => 'WEB-00085', 'Gesamtsumme' => '1.2000'], $dc);

        self::assertSame('WEB-00085', $labels['Bestellnummer']);
        self::assertStringContainsString('1,20', $labels['Gesamtsumme']);
        self::assertStringNotContainsString('1.2000', $labels['Gesamtsumme']);
    }
}
