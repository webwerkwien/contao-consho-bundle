<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Customer;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * A shop without its base group of customers (D-4, K5).
 *
 * The field is mandatory, but a mandatory field only speaks when the mask is
 * saved: every shop from before D-4 has none until then, and a group deleted
 * under "Members → Groups" leaves an id pointing at nothing. Either way the
 * customer list misses the accounts without an order, and nothing else shows
 * it.
 */
final class CustomerGroupIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $shops = $this->connection->fetchAllAssociative(
            'SELECT s.id, s.title FROM tl_consho_shop s
             LEFT JOIN tl_member_group g ON g.id = s.customerGroup
             WHERE g.id IS NULL
             ORDER BY s.id',
        );

        return array_map(
            static fn (array $shop): ConfigurationIssue => new ConfigurationIssue(
                ConfigurationFinding::CustomerGroupMissing,
                ['shop' => (string) $shop['title']],
                (int) $shop['id'],
            ),
            $shops,
        );
    }
}
