<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * tl_consho_subscription as contao/dca/tl_consho_subscription.php creates it
 * (Abo-3) — built from the DCA, see DcaSchema.
 */
final class SubscriptionSchema
{
    public static function create(Connection $connection): void
    {
        DcaSchema::create($connection, 'tl_consho_subscription');
    }
}
