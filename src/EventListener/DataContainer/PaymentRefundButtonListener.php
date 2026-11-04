<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\PaymentActions;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;

/**
 * The refund operations at a payment (block Zahlung Mollie, 4.4), each only
 * where it applies — decided by PaymentActions, which the page "Geldflüsse"
 * asks as well.
 */
class PaymentRefundButtonListener
{
    private readonly PaymentActions $actions;

    public function __construct(RefundPayout $payout, \Closure|null $clock = null)
    {
        $this->actions = new PaymentActions($payout, $clock);
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'list.operations.refundPayment.button')]
    public function refund(DataContainerOperation $operation): void
    {
        if (!$this->actions->canRefund($operation->getRecord())) {
            $operation->hide();
        }
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'list.operations.correctPayment.button')]
    public function correct(DataContainerOperation $operation): void
    {
        if (!$this->actions->canCorrect($operation->getRecord())) {
            $operation->hide();
        }
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'list.operations.refundRetry.button')]
    public function retry(DataContainerOperation $operation): void
    {
        if (!$this->actions->canRetry($operation->getRecord())) {
            $operation->hide();
        }
    }

    #[AsCallback(table: 'tl_consho_payment', target: 'list.operations.refundManual.button')]
    public function manual(DataContainerOperation $operation): void
    {
        if (!$this->actions->canMarkManual($operation->getRecord())) {
            $operation->hide();
        }
    }
}
