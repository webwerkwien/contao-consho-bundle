<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;

/**
 * The property groups a filter element may offer.
 *
 * Only groups a visitor can actually filter by: `filterable`, and an alias the
 * address can carry. Anything else would be dropped from the address the moment
 * a visitor clicked it — and FilterOptionRepository leaves it out of the front
 * end anyway, so offering it here would be a choice that silently does nothing.
 *
 * The opposite of FilterPresetOptionsListener on purpose: the category may be
 * built from any group, the visitor's controls may not.
 */
class FilterGroupOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_content', target: 'fields.conshoGroups.options')]
    public function __invoke(DataContainer|null $dc = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, title, alias FROM tl_consho_property_group WHERE filterable = 1 AND alias != '' ORDER BY title, id",
        );

        $options = [];

        foreach ($rows as $row) {
            // In PHP rather than SQL: SQLite has no REGEXP, and the pattern
            // lives in the codec.
            if (1 === preg_match(FilterStateCodec::ALIAS, (string) $row['alias'])) {
                $options[(int) $row['id']] = (string) $row['title'];
            }
        }

        return $options;
    }
}
