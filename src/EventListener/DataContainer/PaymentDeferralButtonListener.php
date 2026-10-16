<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;

/**
 * "Zahlungsziel verschieben" (E8) only on an order with a term that is not
 * settled and not cancelled. A cheap reading of the row; the page asks the
 * balance (PaymentDeferral::isDeferrable()) and says so where a refund has
 * opened a settled order again.
 */
class PaymentDeferralButtonListener
{
    #[AsCallback(table: 'tl_consho_order', target: 'list.operations.deferPayment.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $record = $operation->getRecord();

        if ((int) ($record['paymentDueAt'] ?? 0) < 1 || null !== ($record['cancelledAt'] ?? null) || null !== ($record['paymentSettledAt'] ?? null)) {
            $operation->hide();
        }
    }
}
