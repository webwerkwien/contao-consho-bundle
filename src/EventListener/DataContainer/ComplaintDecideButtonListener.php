<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Weiter zur Entscheidung" above a complaint's items: back to "Reklamation
 * bearbeiten" once the items are recorded, in the module the list was opened
 * from. Contao would append `key` to the item list's address, with the item
 * table in it; the page belongs to the complaint table.
 */
final class ComplaintDecideButtonListener
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    #[AsCallback(table: 'tl_consho_complaint_item', target: 'list.global_operations.decide.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $query = $this->requests->getCurrentRequest()?->query;
        $complaint = (int) $query?->get('id');

        if (null === $query || $complaint < 1) {
            $operation->hide();

            return;
        }

        $operation->setUrl($this->router->generate('contao_backend', [
            'do' => 'consho_order' === $query->get('do') ? 'consho_order' : 'consho_complaint',
            'table' => 'tl_consho_complaint',
            'key' => 'processComplaint',
            'id' => $complaint,
        ]));
    }
}
