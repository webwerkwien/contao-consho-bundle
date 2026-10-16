<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;

/**
 * Whether a shop issues an invoice at the purchase — that is, whether one of
 * its chosen payment methods triggers `OnOrder` (C6).
 *
 * Asked by two findings that must agree: the number ranges an invoice needs
 * (DbalLegalReadinessIssueSource) and the delivery time a prepayment invoice
 * has to state (DbalInvoiceIssueSource, C-8). One place, so they cannot drift.
 *
 * The trigger comes from the handler, not from a column: it is the handler's
 * decision, and a copy of it in a query would be free to drift from it.
 */
final class OnOrderInvoicing
{
    public function __construct(private readonly PaymentHandlers $handlers)
    {
    }

    /**
     * @param list<int>                               $selected the shop's chosen payment method ids
     * @param array<int|string, array<string, mixed>> $methods  published methods by id: type, jsonData, instructions
     */
    public function issuesOnOrder(array $selected, array $methods): bool
    {
        foreach ($selected as $id) {
            $row = $methods[$id] ?? null;

            if (null === $row || !$this->handlers->has((string) $row['type'])) {
                continue;
            }

            $config = [
                ...MethodSettings::decode($row['jsonData'] ?? null),
                'instructions' => (string) ($row['instructions'] ?? ''),
            ];

            if (DocumentTrigger::OnOrder === $this->handlers->get((string) $row['type'])->documentTrigger($config)) {
                return true;
            }
        }

        return false;
    }
}
