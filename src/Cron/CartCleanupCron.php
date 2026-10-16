<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Cart\CartLifetime;

/**
 * Removes baskets nobody came back to (5.1, decision 2026-09-16).
 *
 * This is a data protection job as much as a tidiness one: from C-5 on,
 * checkoutData carries addresses, a VAT ID result and a date of birth. A
 * basket nobody touched for a month has no reason to keep them.
 *
 * Daily, not minutely: nothing depends on the exact moment, and a nightly
 * delete is one less query per minute on every installation.
 *
 * No marker of "since the last run" is needed — the criterion is each row's
 * own updatedAt, so a cron that was down for a week catches up in one go.
 */
#[AsCronJob('daily')]
class CartCleanupCron
{
    /**
     * @param (\Closure(): int)|null $clock
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly \Closure|null $clock = null,
    ) {
    }

    public function __invoke(): void
    {
        $now = null !== $this->clock ? ($this->clock)() : time();

        // Lines first: deleting the basket first would leave them orphaned if
        // the second statement failed.
        $this->connection->executeStatement(
            "DELETE FROM tl_consho_cart_item
             WHERE pid IN (
                 SELECT id FROM tl_consho_cart
                 WHERE (state = 'open' AND updatedAt < :open)
                    OR (state <> 'open' AND updatedAt < :converted)
             )",
            ['open' => $now - CartLifetime::OPEN, 'converted' => $now - CartLifetime::CONVERTED],
        );

        $this->connection->executeStatement(
            "DELETE FROM tl_consho_cart
             WHERE (state = 'open' AND updatedAt < :open)
                OR (state <> 'open' AND updatedAt < :converted)",
            ['open' => $now - CartLifetime::OPEN, 'converted' => $now - CartLifetime::CONVERTED],
        );

        // The checkout's personal data has a shorter clock than the basket
        // (E1): a week after it was last entered it goes, even where the
        // lines stay. A basket from before checkoutDataAt existed goes by
        // updatedAt, which every save of the data moved as well.
        $this->connection->executeStatement(
            'UPDATE tl_consho_cart SET checkoutData = NULL
             WHERE checkoutData IS NOT NULL
               AND ((checkoutDataAt > 0 AND checkoutDataAt < :limit) OR (checkoutDataAt = 0 AND updatedAt < :limit))',
            ['limit' => $now - CartLifetime::CHECKOUT_DATA],
        );

        // Lines whose basket is already gone — a hand-made database change
        // leaves them, and nothing else ever removes them.
        $this->connection->executeStatement(
            'DELETE FROM tl_consho_cart_item WHERE pid NOT IN (SELECT id FROM tl_consho_cart)',
        );
    }
}
