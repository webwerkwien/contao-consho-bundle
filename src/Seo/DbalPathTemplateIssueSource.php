<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;

/**
 * Product pages whose stored path template is invalid.
 *
 * PathTemplateListener refuses such a template in the back end, but a write
 * past the DCA — CLI, SQL, an import — stores it anyway. ProductPageController::configurePageRoute() then falls back to
 * the default template, so the site keeps routing; what nobody sees is that
 * every product of that page has quietly moved to a different address, and no
 * redirect was recorded for it.
 *
 * The alternatives were a migration (runs once, misses every later write) or a
 * check at load time (the fallback already is one, and it cannot speak). The
 * start page catches every write, however late.
 *
 * Cheap enough for the start page: a site has a handful of product pages, and
 * the rules are ProductPathTemplate::fromString(), the same the mask applies.
 */
final class DbalPathTemplateIssueSource implements ConfigurationIssueSource
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function issues(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, title, conshoPathTemplate FROM tl_page WHERE type = :type AND conshoPathTemplate != '' ORDER BY id",
            ['type' => ProductPageController::TYPE],
        );

        $pages = [];

        foreach ($rows as $row) {
            try {
                ProductPathTemplate::fromString((string) $row['conshoPathTemplate']);
            } catch (InvalidPathTemplate) {
                $pages[] = \sprintf('%s (ID %d)', (string) $row['title'], (int) $row['id']);
            }
        }

        if ([] === $pages) {
            return [];
        }

        return [new ConfigurationIssue(ConfigurationFinding::PathTemplateInvalid, [
            'count' => (string) \count($pages),
            'pages' => implode(', ', $pages),
        ])];
    }
}
