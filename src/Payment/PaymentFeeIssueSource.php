<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingTaxMode;

/**
 * Cash on delivery with a fee, and no choice how that fee is taxed (E-3).
 *
 * The checkout does not offer such a method (E-1, Z3): whether a payment fee
 * is an ancillary supply or one of its own is the operator's to decide, and
 * Consho will not guess. Without this warning the method would simply be
 * missing from the checkout, and nobody would know why.
 *
 * Only the core's cash on delivery: it is the one method whose fee sits in a
 * field. A module computes its fee in code, which no query can see.
 */
final class PaymentFeeIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, title, jsonData, feeTaxMode, feeTaxClass FROM tl_consho_payment_method WHERE published = 1 AND type = ? ORDER BY id',
            [CashOnDeliveryHandler::getType()],
        );

        $issues = [];

        foreach ($rows as $row) {
            if (null === CashOnDeliveryHandler::fee(MethodSettings::decode($row['jsonData'] ?? null))) {
                continue;
            }

            $method = new PaymentMethod(
                (int) $row['id'],
                CashOnDeliveryHandler::getType(),
                (string) $row['title'],
                [],
                ShippingTaxMode::tryFrom((string) ($row['feeTaxMode'] ?? '')),
                (int) ($row['feeTaxClass'] ?? 0),
            );

            if (!$method->feeTaxDecided()) {
                $issues[] = new ConfigurationIssue(ConfigurationFinding::PaymentFeeTaxMissing, ['method' => $method->title]);
            }
        }

        return $issues;
    }
}
