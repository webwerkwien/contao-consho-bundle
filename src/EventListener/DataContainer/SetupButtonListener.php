<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\SetupBackendController;

/**
 * "Einrichtung" in the list of shop configurations (block I, I5): the setup
 * page, scrolled to this shop. It is a view of the dashboard, another module —
 * an href would be appended to `do=consho_shop`.
 *
 * Hidden from a user who may edit shop configurations but not open the
 * dashboard: the click would end in "access denied" (review I, L10).
 */
class SetupButtonListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $router,
        private readonly Security|null $security = null,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'list.operations.setup.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        if (null !== $this->security && !$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'consho_dashboard')) {
            $operation->hide();

            return;
        }

        $operation->setUrl($this->router->generate('contao_backend', SetupBackendController::ROUTE).'#shop-'.(int) ($operation->getRecord()['id'] ?? 0));
    }
}
