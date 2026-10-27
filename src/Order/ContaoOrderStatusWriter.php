<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Writes an automatic status change with a version (spec D 5.3).
 *
 * Through Contao's Versions, not beside it: the order's history in the back
 * end shows who set which status when, and an automatic change must appear
 * there like any other — otherwise "Bezahlt" would be in the record with no
 * trace of how it got there.
 *
 * The user is named explicitly. Versions asks the security helper for the
 * logged-in user and throws without one; in a provider's notification there
 * is none, and on the order page it would be the MEMBER — whose name does
 * not belong in the order's history as the one who set its status.
 */
final class ContaoOrderStatusWriter implements OrderStatusWriterInterface
{
    public const USERNAME = 'Consho';

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly Connection $connection,
        private readonly UrlGeneratorInterface $router,
        // What a cancellation undoes (K6; optional for tests).
        private readonly OrderCancellation|null $cancellation = null,
    ) {
    }

    public function write(int $orderId, StatusFlags $status, int $now): void
    {
        $this->framework->initialize();

        $versions = $this->framework->createInstance(Versions::class, ['tl_consho_order', $orderId]);
        $versions->setUsername(self::USERNAME);
        $versions->setUserId(0);

        // Versions derives the "edit" link from the running request — in a
        // provider's notification that is /_consho/payment/notify/… and leads
        // nowhere (D-1).
        $versions->setEditUrl($this->router->generate('contao_backend', ['do' => 'consho_order', 'act' => 'edit', 'id' => $orderId]));
        $versions->initialize();

        $this->connection->update('tl_consho_order', ['orderStatus' => $status->id, 'tstamp' => $now], ['id' => $orderId]);

        if ($status->isCancelled) {
            // Without the service (tests built by hand) only `cancelledAt` is set.
            ($this->cancellation ?? new OrderCancellation($this->connection))->record($orderId, $now);
        }

        $versions->create();
    }
}
