<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;

/**
 * "Neue Lieferung" only where a parcel can still concern the order.
 *
 * DeliveryCreateVoter refuses the others, but the column list asks it
 * without the order (DC_Table::listView() passes no pid), so the button
 * stayed and its click ended in a silent 403. On the list of one order's
 * deliveries the table is made not creatable instead — the same question
 * the voter asks, with the order from the URL.
 */
class DeliveryCreateButtonListener
{
    public function __construct(
        private readonly RequestStack $requests,
        private readonly DeliveryContents $contents,
    ) {
    }

    #[AsCallback(table: 'tl_consho_delivery', target: 'config.onload')]
    public function __invoke(DataContainer|null $dc = null): void
    {
        $request = $this->requests->getCurrentRequest();

        if (null === $request || !$request->isMethodSafe()) {
            return;
        }

        $query = $request->query;
        $orderId = $query->getInt('id');

        if ($query->has('act') || $query->has('key') || $orderId < 1 || 'tl_consho_delivery' !== $query->getString('table')) {
            return;
        }

        if (!$this->contents->concernsParcels($orderId)) {
            $GLOBALS['TL_DCA']['tl_consho_delivery']['config']['notCreatable'] = true;
        }
    }
}
