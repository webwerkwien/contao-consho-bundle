<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;

/**
 * "Fehlende Shopseiten anlegen" (block I, I1, I2): below a start point, one
 * page per shop function Consho fills itself — with its article and its
 * element — and the link at the start point.
 *
 * ── Only what Consho fills ────────────────────────────────────────────────
 *
 * No legal pages. An empty privacy page that goes live is worse than the
 * finding that says it is missing (I2).
 *
 * ── Adds, never changes ───────────────────────────────────────────────────
 *
 * A page is created only where the start point's field is empty. One that
 * points at a page without its element is left alone — the setup page links
 * it, and the operator decides what that page is.
 *
 * ── Plain inserts for the new rows, a version for the start point ─────────
 *
 * New page, article and element carry no Consho callbacks; the start point
 * does (RootCurrencyListener, ShopSyncTriggerListener) — but those react to
 * the shop and its currency, and only the page links change here. The start
 * point gets a version before and after, like a save in the mask, so the
 * change is in its history with a name.
 *
 * The pages are published, hidden from the navigation, not indexed and not
 * in the sitemap: a basket in the search index helps nobody.
 */
final class ShopPageSetup
{
    /**
     * Start point field => [title key, element type].
     */
    public const PAGES = [
        'consho_cartPage' => ['cart', 'consho_cart'],
        'consho_checkoutPage' => ['checkout', 'consho_checkout'],
        'consho_orderPage' => ['order', 'consho_order'],
        'consho_shippingCostsPage' => ['shippingCosts', 'consho_shipping_costs'],
        'consho_withdrawalFormPage' => ['withdrawal', 'consho_withdrawal'],
        'consho_cancellationPage' => ['cancellation', 'consho_cancellation'],
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
        private readonly UrlGeneratorInterface $router,
        private readonly TranslatorInterface|null $translator = null,
        private readonly CacheTagManager|null $tags = null,
    ) {
    }

    /**
     * The fields of this start point still to be filled.
     *
     * The withdrawal form and the cancellation page only where the findings
     * ask for them — whether a shop has a withdrawal period or sells
     * subscriptions is decided by those sources, and a copy of that rule
     * here would drift from it.
     *
     * @param list<ConfigurationIssue> $issues
     *
     * @return list<string>
     */
    public function missing(int $rootId, int $shopId, array $issues): array
    {
        $root = $this->connection->fetchAssociative('SELECT * FROM tl_page WHERE id = ?', [$rootId]);

        if (false === $root) {
            return [];
        }

        $asked = static fn (ConfigurationFinding ...$findings): bool => [] !== array_filter(
            $issues,
            static fn (ConfigurationIssue $issue): bool => $issue->shopId === $shopId && \in_array($issue->finding, $findings, true),
        );

        $fields = [];

        foreach (array_keys(self::PAGES) as $field) {
            if ((int) ($root[$field] ?? 0) > 0) {
                continue;
            }

            $wanted = match ($field) {
                'consho_withdrawalFormPage' => $asked(ConfigurationFinding::WithdrawalFunctionMissing, ConfigurationFinding::WithdrawalFunctionAdvised),
                'consho_cancellationPage' => $asked(ConfigurationFinding::CancellationPageMissing),
                default => true,
            };

            if ($wanted) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * @param list<string> $fields from missing()
     *
     * @return list<string> the titles of the pages created
     */
    public function create(int $rootId, array $fields, int $userId, int $now): array
    {
        $root = $this->connection->fetchAssociative('SELECT id, language FROM tl_page WHERE id = ?', [$rootId]);
        $fields = array_values(array_filter($fields, static fn (string $field): bool => isset(self::PAGES[$field])));

        if (false === $root || [] === $fields) {
            return [];
        }

        $this->framework->initialize();

        $versions = $this->framework->createInstance(Versions::class, ['tl_page', $rootId]);
        $versions->setEditUrl($this->router->generate('contao_backend', ['do' => 'page', 'act' => 'edit', 'id' => $rootId]));
        $versions->initialize();

        $titles = [];

        $this->connection->transactional(function () use ($root, $rootId, $fields, $userId, $now, &$titles): void {
            $sorting = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(sorting), 0) FROM tl_page WHERE pid = ?', [$rootId]);
            $links = [];

            foreach ($fields as $field) {
                // Read again inside the transaction: a second click must not
                // create a second basket page.
                if ((int) $this->connection->fetchOne(\sprintf('SELECT %s FROM tl_page WHERE id = ?', $field), [$rootId]) > 0) {
                    continue;
                }

                [$key, $type] = self::PAGES[$field];
                $title = $this->title($key, (string) $root['language']);
                $alias = $this->alias($title);
                $sorting += 128;

                $this->connection->insert('tl_page', [
                    'pid' => $rootId,
                    'sorting' => $sorting,
                    'tstamp' => $now,
                    'title' => $title,
                    'alias' => $alias,
                    'type' => 'regular',
                    'robots' => 'noindex,nofollow',
                    'sitemap' => 'map_never',
                    'searchIndexer' => 'never_index',
                    'hide' => 1,
                    'published' => 1,
                ]);
                $pageId = (int) $this->connection->lastInsertId();

                $this->connection->insert('tl_article', [
                    'pid' => $pageId,
                    'sorting' => 128,
                    'tstamp' => $now,
                    'title' => $title,
                    'alias' => $alias,
                    'author' => $userId,
                    'inColumn' => 'main',
                    'published' => 1,
                ]);
                $articleId = (int) $this->connection->lastInsertId();

                $this->connection->insert('tl_content', [
                    'pid' => $articleId,
                    'ptable' => 'tl_article',
                    'sorting' => 128,
                    'tstamp' => $now,
                    'type' => $type,
                ]);

                $links[$field] = $pageId;
                $titles[] = $title;
            }

            if ([] !== $links) {
                $this->connection->update('tl_page', [...$links, 'tstamp' => $now], ['id' => $rootId]);
            }
        });

        if ([] !== $titles) {
            $versions->create();
            $this->tags?->invalidateTags(['contao.db.tl_page.'.$rootId]);
        }

        return $titles;
    }

    /**
     * In the start point's language, through Contao's translator — the page
     * is for the visitor, not for whoever clicked the button.
     */
    private function title(string $key, string $language): string
    {
        $id = 'CONSHO.setup.pageTitles.'.$key;
        $title = $this->translator?->trans($id, [], 'contao_default', '' !== $language ? $language : null);

        return null === $title || $title === $id ? (string) ($GLOBALS['TL_LANG']['CONSHO']['setup']['pageTitles'][$key] ?? $key) : $title;
    }

    /**
     * Unique across the installation, which is stricter than Contao needs
     * (per domain and language) — and never wrong.
     */
    private function alias(string $title): string
    {
        $base = strtolower((string) (new AsciiSlugger())->slug($title));
        $alias = $base;

        for ($i = 2; false !== $this->connection->fetchOne('SELECT id FROM tl_page WHERE alias = ?', [$alias]); ++$i) {
            $alias = $base.'-'.$i;
        }

        return $alias;
    }
}
