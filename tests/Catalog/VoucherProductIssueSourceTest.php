<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VoucherProductIssueSource;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * WG3: kind "Wertgutschein" (voucher) and class "nicht steuerbar" (not
 * taxable) belong together. Type 1 voucher, 2 goods; class 7 not taxable,
 * 8 ordinary.
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
     * The variants carry the value, the base product is not sold.
     * The kind comes from the type of the base product.
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

    /**
     * EZ-1: a single-purpose voucher belongs in a taxed class — there it is no
     * mistake; in the class "nicht steuerbar" it is, and the switch is read
     * from the base product.
     */
    public function testEinzweckGehoertInEineBesteuerteKlasse(): void
    {
        $this->product(1, 'Wandergutschein', 1, 8, '50');
        $this->product(2, 'Wandergutschein steuerfrei', 1, 7, '50');
        $this->product(3, 'Gutschein', 1, 7);
        $this->product(4, 'Gutschein 25 € steuerfrei', 0, 7, '25', 3);
        $this->connection->executeStatement('UPDATE tl_consho_product SET voucherSinglePurpose = 1 WHERE id IN (1, 2, 3)');

        self::assertSame(
            [[ConfigurationFinding::SinglePurposeVoucherNotTaxed, 'Wandergutschein steuerfrei'], [ConfigurationFinding::SinglePurposeVoucherNotTaxed, 'Gutschein 25 € steuerfrei']],
            array_map(static fn ($issue): array => [$issue->finding, $issue->context['product']], (new VoucherProductIssueSource($this->connection))->issues()),
        );
        self::assertTrue(ConfigurationFinding::SinglePurposeVoucherNotTaxed->isWarning());
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
