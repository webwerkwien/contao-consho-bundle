<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;

final class DbalOrderWriter implements OrderWriterInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function createOrder(array $values): int
    {
        $this->connection->insert('tl_consho_order', $values);

        return (int) $this->connection->lastInsertId();
    }

    public function createItem(array $values): void
    {
        $this->connection->insert('tl_consho_order_item', $values);
    }
}
