<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Digital;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * tl_consho_entitlement as contao/dca/tl_consho_entitlement.php creates it
 * (F-4) — one place, so the tests that only need it to exist do not each
 * grow their own copy that drifts from the DCA.
 *
 * Built from the DCA since Abo-3 (DcaSchema), together with
 * tl_consho_subscription: the release looks an order line's subscription up.
 */
final class DigitalSchema
{
    public static function create(Connection $connection): void
    {
        DcaSchema::create($connection, 'tl_consho_entitlement');
        DcaSchema::create($connection, 'tl_consho_subscription');
    }
}
