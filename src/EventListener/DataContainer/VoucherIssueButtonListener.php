<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\VoucherBackendController;

/**
 * "Gutschein ausstellen" only for administrators, like the action itself
 * (VoucherBackendController::issue()): a goodwill voucher is money nobody
 * paid, in any amount, for any shop of the installation. The module right
 * alone would hand that to every editor who may see the voucher list.
 */
final class VoucherIssueButtonListener
{
    public function __construct(private readonly Security $security)
    {
    }

    #[AsCallback(table: 'tl_consho_voucher', target: 'list.global_operations.issueVoucher.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        if (!$this->security->isGranted(VoucherBackendController::ISSUE_ROLE)) {
            $operation->hide();
        }
    }
}
