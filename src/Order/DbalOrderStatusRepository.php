<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Doctrine\DBAL\Connection;

class DbalOrderStatusRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function flags(int $statusId): StatusFlags|null
    {
        if ($statusId < 1) {
            return null;
        }

        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order_status WHERE id = ?', [$statusId]);

        return false === $row ? null : StatusFlags::fromRow($row);
    }

    /**
     * The statuses of one shop, in their sorting — what an order of that shop
     * may be set to. Statuses hang at the shop (ptable), so another shop's
     * "Bezahlt" is not an option here.
     *
     * @return array<int, string> id => title
     */
    public function titlesOfShop(int $shopId): array
    {
        $titles = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title FROM tl_consho_order_status WHERE pid = ? ORDER BY sorting, id', [$shopId]) as $row) {
            $titles[(int) $row['id']] = (string) $row['title'];
        }

        return $titles;
    }
}
