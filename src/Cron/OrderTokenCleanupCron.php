<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cron;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCronJob;
use Doctrine\DBAL\Connection;

/**
 * Removes order tokens past their expiry (D-4, K4).
 *
 * Until D-4 only download tokens expired, and nothing removed them. "Meine
 * Bestellungen" issues a short-lived token on every click, so expired rows
 * now grow with use.
 *
 * Since D-6 every token expires (TokenPurpose::defaultLifetime()); rows
 * without an expiry are older than that and the D-6 migration gives them one.
 * `IS NOT NULL` stays so this job never deletes a row it cannot judge. An
 * expired token opens nothing — TokenValidator answers it exactly as an unknown one —
 * so deleting it takes nothing away.
 *
 * `expiresAt < now`, not `<=`, like TokenValidator: a token expiring this very
 * second still opens the order.
 */
#[AsCronJob('daily')]
class OrderTokenCleanupCron
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

        $this->connection->executeStatement(
            'DELETE FROM tl_consho_order_token WHERE expiresAt IS NOT NULL AND expiresAt < :now',
            ['now' => $now],
        );
    }
}
