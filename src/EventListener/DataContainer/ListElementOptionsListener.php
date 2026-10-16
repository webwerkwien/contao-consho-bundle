<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * The listings a filter element can be tied to explicitly.
 *
 * Named "page › article › ID" for a listing in an article and "Theme … › ID"
 * for one in a layout section. The layout case is the reason the field exists
 * at all: an empty field finds the first listing in the page's articles
 * (ListingPageRepository::firstOnPage()), never one in a layout section.
 *
 * Only listings that follow the address. For any other the filter element
 * renders nothing, so offering it would be a choice without effect.
 */
class ListElementOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string>
     */
    #[AsCallback(table: 'tl_content', target: 'fields.conshoListElement.options')]
    public function __invoke(DataContainer|null $dc = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT c.id, p.title AS pageTitle, a.title AS articleTitle, t.name AS themeName
                FROM tl_content c
                LEFT JOIN tl_article a ON c.ptable = 'tl_article' AND a.id = c.pid
                LEFT JOIN tl_page p ON p.id = a.pid
                LEFT JOIN tl_theme t ON c.ptable = 'tl_theme' AND t.id = c.pid
                WHERE c.type = 'consho_product_list' AND c.conshoFollowsAddress = 1
                SQL,
        );

        $options = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $options[$id] = match (true) {
                null !== $row['articleTitle'] => \sprintf('%s › %s › ID %d', $row['pageTitle'] ?? '', $row['articleTitle'], $id),
                null !== $row['themeName'] => \sprintf('Theme %s › ID %d', $row['themeName'], $id),
                // Nested in another element: no name worth building a path for.
                default => \sprintf('ID %d', $id),
            };
        }

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return $options;
    }
}
