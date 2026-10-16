<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;

/**
 * The price struck through next to a promotion on the product page (block H,
 * H-2) — read from the projection row the tile reads, never worked out a
 * second time, so tile and page cannot name two different prices.
 *
 * Only for what the projection describes: a guest's one-time promotion at
 * quantity one. The page passes the price it shows, and the projected figure
 * has to be that very price — a member paying a group's own promotion, or a
 * plan, is a different figure, and the struck price belongs to the guest's.
 */
class ComparePrices
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{string, string}|null net and gross struck through, or null
     */
    public function for(int $articleId, string $currency, string $country, ResolvedPrice $shown): array|null
    {
        if (PriceSource::Promotion !== $shown->source || null !== $shown->customerGroup) {
            return null;
        }

        $row = $this->connection->fetchAssociative(
            'SELECT net, subscriptionRule, compareNet, compareGross FROM tl_consho_product_list_price
              WHERE pid = ? AND currency = ? AND country = ?',
            [$articleId, $currency, $country],
        );

        if (false === $row || null === $row['compareGross'] || 0 !== (int) $row['subscriptionRule']
            || !BigDecimal::of((string) $row['net'])->isEqualTo($shown->net)) {
            return null;
        }

        return [(string) $row['compareNet'], (string) $row['compareGross']];
    }
}
