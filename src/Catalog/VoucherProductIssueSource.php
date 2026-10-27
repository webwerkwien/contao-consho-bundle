<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Vouchers and their tax class, kept together (block Wertgutscheine, WG3).
 *
 * The kind "Wertgutschein" is at the product type, "nicht steuerbar" at the
 * tax class — two places the operator sets, and each without the other is a
 * mistake with money attached:
 *
 *  - a voucher in a taxed class is sold with tax, and taxed again on what it
 *    buys — a warning, unless it is sold as single-purpose (EZ-1);
 *  - a single-purpose voucher in the "nicht steuerbar" class is sold without
 *    tax and so issued as a multi-purpose one (OrderFactory) — a warning;
 *  - an ordinary product in the "nicht steuerbar" class is sold without tax
 *    — a warning;
 *  - a voucher without a value is issued at what was paid for it — a note.
 *
 * Kind by the rule of DbalArticleShippingData (the main product's type for a
 * variant, none at all physical); the class is the article's own, as
 * DbalArticleTaxClasses reads it. Published articles that are sold — a main
 * product with variants is not, its variants are.
 */
final class VoucherProductIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT a.title, a.voucherValue,
                    CASE WHEN a.pid > 0 THEN p.voucherSinglePurpose ELSE a.voucherSinglePurpose END AS singlePurpose,
                    COALESCE(tm.kind, ta.kind, 'physical') AS kind,
                    COALESCE(c.notTaxable, 0) AS notTaxable
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product p ON p.id = a.pid AND a.pid > 0
               LEFT JOIN tl_consho_product_type tm ON tm.id = p.productType
               LEFT JOIN tl_consho_product_type ta ON ta.id = a.productType
               LEFT JOIN tl_consho_tax_class c ON c.id = a.taxClass
              WHERE a.published = 1
                AND NOT EXISTS (SELECT 1 FROM tl_consho_product v WHERE v.pid = a.id)
              ORDER BY a.id",
        );

        $issues = [];

        foreach ($rows as $row) {
            $voucher = ProductKind::Voucher === ProductKind::stored((string) $row['kind']);
            $notTaxable = (bool) $row['notTaxable'];
            $singlePurpose = $voucher && (bool) $row['singlePurpose'];
            $context = ['product' => (string) $row['title']];

            if ($voucher && !$notTaxable && !$singlePurpose) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::VoucherTaxed, $context);
            }

            if ($singlePurpose && $notTaxable) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::SinglePurposeVoucherNotTaxed, $context);
            }

            if (!$voucher && $notTaxable) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::NotTaxableClassOnGoods, $context);
            }

            if ($voucher && !BigDecimal::of(is_numeric($row['voucherValue']) ? (string) $row['voucherValue'] : '0')->isPositive()) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::VoucherValueMissing, $context);
            }
        }

        return $issues;
    }
}
