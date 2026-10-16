<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VoucherProductIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * WG3: Art „Wertgutschein" und Klasse „nicht steuerbar" gehören zusammen.
 * Typ 1 Gutschein, 2 Ware; Klasse 7 nicht steuerbar, 8 gewöhnlich.
 */
class VoucherProductIssueSourceTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_tax_class'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_product_type', ['id' => 1, 'title' => 'Gutschein', 'kind' => 'voucher']);
        $this->connection->insert('tl_consho_product_type', ['id' => 2, 'title' => 'Ware', 'kind' => 'physical']);
        $this->connection->insert('tl_consho_tax_class', ['id' => 7, 'title' => 'Wertgutschein', 'notTaxable' => 1]);
        $this->connection->insert('tl_consho_tax_class', ['id' => 8, 'title' => 'Waren']);
    }

    public function testRichtigEingerichtetNichts(): void
    {
        $this->product(1, 'Gutschein 50 €', 1, 7, '50');
        $this->product(2, 'Wanderstock', 2, 8);

        self::assertSame([], (new VoucherProductIssueSource($this->connection))->issues());
    }

    public function testDieDreiWidersprueche(): void
    {
        $this->product(1, 'Gutschein besteuert', 1, 8, '50');
        $this->product(2, 'Wanderstock steuerfrei', 2, 7);
        $this->product(3, 'Gutschein ohne Wert', 1, 7);

        self::assertSame([
            [ConfigurationFinding::VoucherTaxed, 'Gutschein besteuert'],
            [ConfigurationFinding::NotTaxableClassOnGoods, 'Wanderstock steuerfrei'],
            [ConfigurationFinding::VoucherValueMissing, 'Gutschein ohne Wert'],
        ], array_map(static fn ($issue): array => [$issue->finding, $issue->context['product']], (new VoucherProductIssueSource($this->connection))->issues()));

        self::assertTrue(ConfigurationFinding::VoucherTaxed->isWarning());
        self::assertTrue(ConfigurationFinding::NotTaxableClassOnGoods->isWarning());
        self::assertFalse(ConfigurationFinding::VoucherValueMissing->isWarning());
    }

    /**
     * Die Varianten tragen den Wert, das Grundprodukt wird nicht verkauft.
     * Die Art kommt vom Typ des Grundprodukts.
     */
    public function testVariantenStattGrundprodukt(): void
    {
        $this->product(1, 'Gutschein', 1, 7);
        $this->product(2, 'Gutschein 25 €', 0, 7, '25', 1);
        $this->product(3, 'Gutschein ohne Wert', 0, 7, '', 1);

        self::assertSame(
            [[ConfigurationFinding::VoucherValueMissing, 'Gutschein ohne Wert']],
            array_map(static fn ($issue): array => [$issue->finding, $issue->context['product']], (new VoucherProductIssueSource($this->connection))->issues()),
        );
    }

    public function testUnveroeffentlichtZaehltNicht(): void
    {
        $this->product(1, 'Gutschein besteuert', 1, 8, '50', published: false);

        self::assertSame([], (new VoucherProductIssueSource($this->connection))->issues());
    }

    private function product(int $id, string $title, int $type, int $taxClass, string $value = '', int $pid = 0, bool $published = true): void
    {
        $this->connection->insert('tl_consho_product', [
            'id' => $id, 'pid' => $pid, 'title' => $title, 'productType' => $type, 'taxClass' => $taxClass,
            'voucherValue' => '' === $value ? '0.0000' : $value, 'published' => $published ? 1 : 0,
        ]);
    }
}
