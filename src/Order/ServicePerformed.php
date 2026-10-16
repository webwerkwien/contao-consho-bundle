<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * "Leistung erbracht" (Dienstleistung, DL3): the operator reports a service
 * line as fully rendered.
 *
 * With the consumer's consent to the early start that ends the right of
 * withdrawal for the line (§ 356 Abs. 4 BGB, § 18 Abs. 1 Z 1 FAGG) —
 * DbalWithdrawalRepository counts it as used up from then on. Without the
 * consent it changes nothing there, and the page simply says it.
 *
 * Only where it means something:
 *  - a service: goods are delivered, content is released;
 *  - released: what was never unlocked was never rendered;
 *  - not withdrawn: a revoked entitlement (DL9) ends the contract;
 *  - no subscription: a membership is never "fully rendered" while it runs,
 *    it ends — by notice or by withdrawal (A12);
 *  - once: it cannot be taken back, so a second report is not a correction.
 *
 * Under the order's lock, like a delivery report: a withdrawal decided at
 * the same moment must see either both or neither.
 */
class ServicePerformed
{
    public const PERFORMED = 'performed';
    public const NOT_SERVICE = 'notService';
    public const NOT_RELEASED = 'notReleased';
    public const SUBSCRIPTION = 'subscription';
    public const ALREADY_PERFORMED = 'alreadyPerformed';
    // Withdrawn and revoked (DL9): there is nothing left to render.
    public const WITHDRAWN = 'withdrawn';

    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
    ) {
    }

    /**
     * @return self::* what happened; only PERFORMED wrote anything
     */
    public function report(int $orderItemId, int $now): string
    {
        $this->connection->beginTransaction();

        try {
            $orderId = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_order_item WHERE id = ?', [$orderItemId]);
            $order = $orderId > 0 ? $this->orders->lockOrder($orderId) : null;
            $item = null === $order ? false : $this->connection->fetchAssociative('SELECT * FROM tl_consho_order_item WHERE id = ?', [$orderItemId]);
            $outcome = false === $item ? self::NOT_SERVICE : $this->check($item);

            if (self::PERFORMED === $outcome) {
                $this->connection->update('tl_consho_order_item', ['performedAt' => $now, 'tstamp' => $now], ['id' => $orderItemId]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return $outcome;
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return self::*
     */
    private function check(array $item): string
    {
        if (!OrderLineType::stored((string) $item['lineType'])->isGoods() || ProductKind::Service !== ProductKind::stored((string) $item['kind'])) {
            return self::NOT_SERVICE;
        }

        if ((int) $item['subscriptionRule'] > 0) {
            return self::SUBSCRIPTION;
        }

        if (null !== $item['performedAt'] && '' !== (string) $item['performedAt']) {
            return self::ALREADY_PERFORMED;
        }

        $revoked = $this->connection->fetchFirstColumn('SELECT revoked FROM tl_consho_entitlement WHERE orderItem = ?', [(int) $item['id']]);

        return match (true) {
            [] === $revoked => self::NOT_RELEASED,
            [] === array_filter($revoked, static fn (mixed $flag): bool => 0 === (int) $flag) => self::WITHDRAWN,
            default => self::PERFORMED,
        };
    }
}
