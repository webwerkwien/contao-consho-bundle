<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Kaufbestellung" in the subscription list (Abo-8, P80): the order the
 * subscription was bought with, opened in the order module. The operation's
 * own href would point at the subscription's id, so the address is set here
 * from `firstOrder`.
 */
class SubscriptionOrderButtonListener
{
    public function __construct(private readonly UrlGeneratorInterface $router)
    {
    }

    #[AsCallback(table: 'tl_consho_subscription', target: 'list.operations.order.button')]
    public function __invoke(DataContainerOperation $operation): void
    {
        $orderId = (int) ($operation->getRecord()['firstOrder'] ?? 0);

        if ($orderId < 1) {
            $operation->disable();

            return;
        }

        $operation->setUrl($this->router->generate('contao_backend', ['do' => 'consho_order', 'act' => 'edit', 'id' => $orderId]));
    }
}
