<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;

/**
 * How an article is delivered digitally and for how long (F-4).
 *
 * Read at the RELEASE, not frozen at the purchase: nothing has been handed
 * out before, and a file replaced between order and release is the file the
 * customer should get. What the customer was promised about the term is in
 * the product text they bought on.
 *
 * A variant's own value where it has one, otherwise its main product's — the
 * rule of the shipping data (DbalArticleShippingData).
 */
class DigitalProductSettings
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{type: string, months: int}
     */
    public function of(int $articleId): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.digitalDelivery, a.entitlementMonths,
                    p.digitalDelivery AS mainDelivery, p.entitlementMonths AS mainMonths
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product p ON p.id = a.pid AND a.pid > 0
              WHERE a.id = ?',
            [$articleId],
        );

        if (false === $row) {
            return ['type' => '', 'months' => 0];
        }

        $type = (string) ($row['digitalDelivery'] ?? '');
        $months = (int) ($row['entitlementMonths'] ?? 0);

        return [
            'type' => '' !== $type ? $type : (string) ($row['mainDelivery'] ?? ''),
            'months' => $months > 0 ? $months : (int) ($row['mainMonths'] ?? 0),
        ];
    }
}
