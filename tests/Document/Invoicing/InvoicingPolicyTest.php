<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class InvoicingPolicyTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_shop');
    }

    public function testConshoNummeriertUndVersendet(): void
    {
        $invoicing = $this->policy(new FakeInvoiceProvider())->forShop($this->shop('consho'));

        self::assertSame(['consho', true, true, false], [$invoicing->issuer, $invoicing->conshoNumbers, $invoicing->conshoSends, $invoicing->moduleIssues()]);
        self::assertNull($invoicing->provider);
    }

    public function testEinNeuerShopStehtAufConsho(): void
    {
        $this->connection->insert('tl_consho_shop', ['title' => 'Neu']);

        self::assertSame('consho', $this->policy(new FakeInvoiceProvider())->forShop((int) $this->connection->lastInsertId())->issuer);
    }

    public function testEinWeiterreichendesModulBrauchtConshoNummern(): void
    {
        // R19: the numbers stay Consho's, so do the number ranges.
        $provider = new FakeInvoiceProvider(issues: false, sends: false);
        $invoicing = $this->policy($provider)->forShop($this->shop('fake_books'));

        self::assertSame(['consho', true, true, false], [$invoicing->issuer, $invoicing->conshoNumbers, $invoicing->conshoSends, $invoicing->moduleIssues()]);
        self::assertSame($provider, $invoicing->provider);
    }

    public function testEinWeiterreichendesModulKannSelbstVersenden(): void
    {
        $invoicing = $this->policy(new FakeInvoiceProvider(issues: false, sends: true))->forShop($this->shop('fake_books'));

        self::assertTrue($invoicing->conshoNumbers);
        self::assertFalse($invoicing->conshoSends);
    }

    public function testEinAusstellendesModulNummeriertSelbst(): void
    {
        $invoicing = $this->policy(new FakeInvoiceProvider(issues: true, sends: false))->forShop($this->shop('fake_books'));

        self::assertSame(['fake_books', false, true, true], [$invoicing->issuer, $invoicing->conshoNumbers, $invoicing->conshoSends, $invoicing->moduleIssues()]);
    }

    public function testEinAusstellendesModulDasSelbstVersendet(): void
    {
        $invoicing = $this->policy(new FakeInvoiceProvider(issues: true, sends: true))->forShop($this->shop('fake_books'));

        self::assertFalse($invoicing->conshoSends);
    }

    public function testEinNichtInstalliertesModulLaesstDieBelegeWarten(): void
    {
        $invoicing = $this->policy(new FakeInvoiceProvider())->forShop($this->shop('removed_books'));

        self::assertSame(['removed_books', false, false, true], [$invoicing->issuer, $invoicing->conshoNumbers, $invoicing->conshoSends, $invoicing->moduleIssues()]);
        self::assertNull($invoicing->provider);
    }

    public function testAusNummeriertNichtUndVersendetNicht(): void
    {
        $invoicing = $this->policy(new FakeInvoiceProvider())->forShop($this->shop('off'));

        self::assertSame(['off', false, false, false], [$invoicing->issuer, $invoicing->conshoNumbers, $invoicing->conshoSends, $invoicing->moduleIssues()]);
    }

    private function shop(string $invoicing): int
    {
        $this->connection->insert('tl_consho_shop', ['title' => 'Shop', 'invoicing' => $invoicing]);

        return (int) $this->connection->lastInsertId();
    }

    private function policy(FakeInvoiceProvider $provider): InvoicingPolicy
    {
        return new InvoicingPolicy($this->connection, new InvoiceProviders(new ServiceLocator(['fake_books' => static fn () => $provider])));
    }
}
