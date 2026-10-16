<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The highest age restriction in a basket (C19, C-4).
 *
 * A question about the whole basket, not about one line, which is why it does
 * not ride along on CartTitles: that answers what a line is called, and an age
 * limit is not a name. One query either way.
 *
 * ── The higher of both ────────────────────────────────────────────────────
 *
 * Variant and main product, as ProductDetailAssembler puts it: `minimumAge:
 * max($main->minimumAge, $article->minimumAge)`. A variant may raise the limit
 * of its product, never lower it — a 40 % edition of a herbal bitters is not
 * less restricted because the range as a whole is sold from 16.
 *
 * ── What it does NOT do ───────────────────────────────────────────────────
 *
 * It does not block anything. Ages 6, 12 and 14 are a notice by definition
 * (C19), and 16 and 18 are stopped at the checkout, where there is an identity
 * to check against — a verified member group, a shipping method that checks on
 * delivery, or neither, in which case the order is refused. That is C-5.
 *
 * The basket says it early, because a customer should learn before the address
 * form that the bottle in front of them will need an ID at the door.
 */
final class CartAgeLimit
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param list<int> $articleIds
     *
     * @return int the highest age over all of them, 0 for none
     */
    public function of(array $articleIds): int
    {
        if ([] === $articleIds) {
            return 0;
        }

        /*
         * Also over articles that have no price: an unpriced line is still in
         * the basket and still in front of the customer. It cannot be bought,
         * but the notice is about what lies there, not about what is payable.
         */
        $highest = $this->connection->fetchOne(
            'SELECT MAX(CASE WHEN m.minimumAge > a.minimumAge THEN m.minimumAge ELSE a.minimumAge END)
             FROM tl_consho_product a
             JOIN tl_consho_product m ON m.id = COALESCE(NULLIF(a.pid, 0), a.id)
             WHERE a.id IN (:ids)',
            ['ids' => array_values(array_unique($articleIds))],
            ['ids' => ArrayParameterType::INTEGER],
        );

        return (int) $highest;
    }
}
