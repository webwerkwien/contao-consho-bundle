<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Pins that point at no main product (B-7).
 *
 * In the front end such a pin renders nothing, which is right. In the back end
 * it must not vanish silently, or somebody looks in vain for the product that
 * was meant to stand on top.
 *
 * "No main product" includes one that became a variant later: a listing shows
 * main products only, so the pin is just as empty.
 *
 * One aggregate query, as every source here: it runs on every call of the
 * start page.
 */
final class DbalPinnedProductIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $count = (int) $this->connection->fetchOne(
            'SELECT COUNT(*)
             FROM tl_content c
             LEFT JOIN tl_consho_product p ON p.id = c.conshoProduct AND p.pid = 0
             WHERE c.type = ? AND p.id IS NULL',
            [DbalPinRepository::TYPE],
        );

        if ($count < 1) {
            return [];
        }

        return [new ConfigurationIssue(ConfigurationFinding::PinnedProductMissing, ['count' => (string) $count])];
    }
}
