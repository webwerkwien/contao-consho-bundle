<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerType;

final class DbalOrderWriter implements OrderWriterInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Freezes the shop's customer type and withdrawal period onto the order
     * (tl_consho_order.customerType, withdrawalDays, withdrawalGraceDays).
     *
     * Here rather than in OrderFactory: every order is written through this
     * method, a purchase and a subscription's follow-up order alike, and the
     * values are the shop's, not the draft's. Values the caller passes win.
     */
    public function createOrder(array $values): int
    {
        $shop = $this->connection->fetchAssociative(
            'SELECT customerType, withdrawalDays, withdrawalGraceDays FROM tl_consho_shop WHERE id = ?',
            [(int) ($values['shop'] ?? 0)],
        );

        if (false !== $shop) {
            $values += [
                'customerType' => CustomerType::of($shop['customerType'])->value,
                'withdrawalDays' => (int) $shop['withdrawalDays'],
                'withdrawalGraceDays' => (int) $shop['withdrawalGraceDays'],
            ];
        }

        $this->connection->insert('tl_consho_order', $values);

        return (int) $this->connection->lastInsertId();
    }

    public function createItem(array $values): void
    {
        $this->connection->insert('tl_consho_order_item', $values);
    }
}
