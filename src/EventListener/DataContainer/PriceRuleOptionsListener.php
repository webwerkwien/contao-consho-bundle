<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;

/**
 * Choices of the catalogue price rule mask (block H, H-3).
 *
 * Products: main products only — a variant matches through its main product,
 * and a list holding both would offer a choice that says nothing more.
 * Property options: grouped as "group: option", the way the filter shows them.
 *
 * Registered by attribute, not as `[self::class, 'method']` in the DCA: Contao
 * resolves such an array through the container, and this service is private
 * — the mask died with a 500 at the first new rule (acceptance of H on c5,
 * 2026-10-05). No test built the real container (rule 25);
 * DcaCallbackRegistrationTest now refuses the array form for bundle classes.
 */
class PriceRuleOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_price_rule', target: 'fields.products.options')]
    #[AsCallback(table: 'tl_consho_cart_rule', target: 'fields.products.options')]
    public function products(): array
    {
        return array_map(strval(...), $this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_product WHERE pid = 0 ORDER BY title, id'));
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_consho_price_rule', target: 'fields.propertyOptions.options')]
    public function propertyOptions(): array
    {
        return array_map(strval(...), $this->connection->fetchAllKeyValue(
            "SELECT o.id, CONCAT(g.title, ': ', o.title)
               FROM tl_consho_property_option o
               JOIN tl_consho_property_group g ON g.id = o.pid
              ORDER BY g.title, o.sorting, o.id",
        ));
    }
}
