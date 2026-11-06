<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Complaint;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryContents;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Withdrawal\ProcessingResult;

/**
 * "Ersatzlieferung anlegen" (block Reklamation, RK-5, S2): a delivery of the
 * complaint's order with the complaint's reference, prefilled with what is
 * still to be replaced — the complained quantities less the complaint's
 * other replacement deliveries. The operator opens it in the delivery mask
 * like any other draft, adjusts the contents for a partial replacement and
 * reports it there (DeliveryReported).
 *
 * Under the order lock, like every change to the order's deliveries: two
 * operators pressing the button at once get one draft with everything and a
 * "nothing left to replace".
 */
final class ReplacementDelivery
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly DeliveryContents $contents,
    ) {
    }

    /**
     * Done with the complaint's number and the new delivery's id as params.
     */
    public function create(int $complaintId, int $now): ProcessingResult
    {
        $orderId = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_complaint WHERE id = ?', [$complaintId]);

        if ($orderId < 1) {
            return ProcessingResult::refused('unknown');
        }

        $this->connection->beginTransaction();

        try {
            $order = $this->orders->lockOrder($orderId);
            $complaint = null === $order ? false : $this->connection->fetchAssociative('SELECT * FROM tl_consho_complaint WHERE id = ?', [$complaintId]);
            $result = false === $complaint ? ProcessingResult::refused('unknown') : $this->insert($complaint, $orderId, $now);

            $result->done ? $this->connection->commit() : $this->connection->rollBack();

            return $result;
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $complaint
     */
    private function insert(array $complaint, int $orderId, int $now): ProcessingResult
    {
        if (\in_array($complaint['state'], ['resolved', 'rejected'], true)) {
            return ProcessingResult::refused('alreadyDecided');
        }

        if ('replacement' !== $complaint['remedy'] || null === $complaint['decidedAt']) {
            return ProcessingResult::refused('notReplacement');
        }

        $complaintId = (int) $complaint['id'];
        $items = $this->contents->replaceable($complaintId, 0);

        if ([] === $items) {
            return ProcessingResult::refused('nothingToReplace', [(string) $complaint['number']]);
        }

        // As DeliveryListener::onCreate: shipped "now" until the operator says otherwise.
        $this->connection->insert('tl_consho_delivery', [
            'pid' => $orderId,
            'tstamp' => $now,
            'shippedAt' => $now,
            'carrier' => '',
            'trackingNumber' => '',
            'complaint' => $complaintId,
        ]);

        $deliveryId = (int) $this->connection->lastInsertId();
        $this->contents->replace($deliveryId, $items, $now);

        return ProcessingResult::done('replacementCreated', [(string) $complaint['number'], (string) $deliveryId]);
    }
}
