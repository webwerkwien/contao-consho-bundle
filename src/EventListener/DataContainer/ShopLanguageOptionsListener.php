<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;

/**
 * The languages a default language can be chosen from.
 *
 * These are the languages of the root pages that point at this shop — not the
 * languages of the Contao installation. A shop should not be able to carry a
 * default language it is not even reachable in.
 */
class ShopLanguageOptionsListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_shop', target: 'fields.fallbackLanguage.options')]
    public function __invoke(DataContainer|null $dc = null): array
    {
        $shopId = null !== $dc ? (int) $dc->id : 0;

        if ($shopId < 1) {
            return [];
        }

        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT language FROM tl_page WHERE type = 'root' AND consho_shop = ? AND language != '' ORDER BY language",
            [$shopId],
        );

        return array_combine($rows, $rows);
    }
}
