<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentFeeIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * E-3: cash on delivery with a fee but without a tax choice is not offered
 * at checkout (E-1, Z3). Without a warning it would be missing there silently.
 */
class PaymentFeeIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_payment_method');
    }

    public function testGebuehrOhneSteuerwahlIstEineWarnung(): void
    {
        $this->method(1, 'Nachnahme', fee: '3.50');

        $issues = (new PaymentFeeIssueSource($this->connection))->issues();

        self::assertCount(1, $issues);
        self::assertSame(ConfigurationFinding::PaymentFeeTaxMissing, $issues[0]->finding);
        self::assertTrue($issues[0]->finding->isWarning());
        self::assertSame(['method' => 'Nachnahme'], $issues[0]->context);
    }

    public function testFestOhneKlasseZaehltAlsNichtGewaehlt(): void
    {
        $this->method(1, 'Nachnahme', fee: '3.50', mode: 'fixed');

        self::assertCount(1, (new PaymentFeeIssueSource($this->connection))->issues());
    }

    public function testKeinBefundOhneGebuehrMitWahlOderUnveroeffentlicht(): void
    {
        $this->method(1, 'Nachnahme ohne Gebühr');
        $this->method(2, 'Nachnahme anteilig', fee: '3.50', mode: 'proportional');
        $this->method(3, 'Nachnahme fest', fee: '3.50', mode: 'fixed', class: 1);
        $this->method(4, 'Nachnahme Entwurf', fee: '3.50', published: false);
        $this->connection->insert('tl_consho_payment_method', ['id' => 5, 'title' => 'Vorkasse', 'type' => 'prepayment', 'published' => 1]);

        self::assertSame([], (new PaymentFeeIssueSource($this->connection))->issues());
    }

    private function method(int $id, string $title, string $fee = '', string $mode = '', int $class = 0, bool $published = true): void
    {
        $this->connection->insert('tl_consho_payment_method', [
            'id' => $id,
            'title' => $title,
            'type' => 'cod',
            'published' => $published ? 1 : 0,
            'jsonData' => json_encode(['fee' => $fee, 'feeCurrency' => 'EUR']),
            'feeTaxMode' => $mode,
            'feeTaxClass' => $class,
        ]);
    }
}
