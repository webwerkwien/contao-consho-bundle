<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Widerruf erfassen" above an order's withdrawals.
 *
 * Contao would append `key` to the list's address, with the withdrawal table
 * in it and the order's id read as a withdrawal's; the page belongs to the
 * order (WithdrawalCaptureBackendController). Only under an order — the menu
 * item "Widerrufe" has none — and only for whoever may update the order,
 * which recording requires. Not on an anonymised order.
 */
final class WithdrawalCaptureButtonListener
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly UrlGeneratorInterface $router,
        private readonly Connection $connection,
        private readonly Security $security,
    ) {
    }

    #[AsCallback(table: 'tl_consho_withdrawal', target: 'list.global_operations.capture.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $query = $this->requests->getCurrentRequest()?->query;
        $orderId = (int) $query?->get('id');
        $order = null !== $query && 'consho_order' === $query->get('do') && $orderId > 0
            ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$orderId])
            : false;

        // An anonymised order has no address and no customer to record for.
        if (false === $order || null !== ($order['anonymizedAt'] ?? null) || !$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            $operation->hide();

            return;
        }

        $operation->setUrl($this->router->generate('contao_backend', [
            'do' => 'consho_order',
            'key' => 'captureWithdrawal',
            'id' => $orderId,
        ]));
    }
}
