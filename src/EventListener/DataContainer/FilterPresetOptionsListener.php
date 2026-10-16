<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * The property values an editor can build a category from.
 *
 * Grouped by property group, because a flat list of "rot, blau, 250 ml, Bio,
 * Restposten" stops being usable at about thirty entries — and a shop that
 * filters on six dimensions passes that on day one.
 *
 * ⚠️ `filterable` is deliberately NOT applied here. It governs what a VISITOR
 * may filter by; this list is the editor choosing what a page contains. A shop
 * may well build a category on a dimension it never offers as a filter —
 * "Aktion" being the obvious one.
 */
class FilterPresetOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, array<int, string>>
     */
    #[AsCallback(table: 'tl_content', target: 'fields.conshoFilter.options')]
    public function __invoke(DataContainer|null $dc = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id AS id, o.title AS title, g.title AS groupTitle
                FROM tl_consho_property_option o
                JOIN tl_consho_property_group g ON g.id = o.pid
                ORDER BY g.title, o.title
                SQL,
        );

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['groupTitle']][(int) $row['id']] = (string) $row['title'];
        }

        return $options;
    }
}
