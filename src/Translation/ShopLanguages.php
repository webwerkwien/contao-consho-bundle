<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which ways a shop is reached — language and, since C-1c, currency — and
 * which language of them is its default.
 *
 * Both come from the root pages that point at the shop: A-6 fixes n root pages
 * : 1 shop, and spec 4.0 puts language and currency on the same level, the
 * access route. A second, separate list on the shop would drift apart from the
 * page tree, and unnoticed at that.
 *
 * Cached per shop within the request: the listing, the tiles, the sitemap and
 * the detail page all ask the same thing more than once.
 *
 * ⚠️ The class outgrew its name with audiencesOf() — it is no longer only
 * about languages. Renaming it touches the whole bundle and is not part of
 * C-1c task 4; it is reported instead of done.
 */
final class ShopLanguages implements ResetInterface
{
    /** @var array<int, list<array{language: string, currency: string}>> */
    private array $audiences = [];

    /** @var array<int, string> */
    private array $fallbacks = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Forgets what was cached.
     *
     * Between requests through kernel.reset — and within one by whoever wrote
     * a shop or a root page and asks again afterwards: the visibility
     * projection runs at the end of the very request that saved the shop, and
     * with the cache it would still calculate with the old default language
     * (B-10, finding 3).
     */
    public function reset(): void
    {
        $this->audiences = [];
        $this->fallbacks = [];
    }

    /**
     * All languages of the shop, sorted ascending.
     *
     * ⚠️ Unchanged by C-1c, on purpose: the translation side stands on this
     * list and a currency more or less must not move it. The empty language is
     * dropped HERE, not in the query, so audiencesOf() can still report a root
     * page that has none.
     *
     * The ascending order comes from audiencesOf()'s ORDER BY, not from a
     * sort() here — one would never turn red, because the rows already arrive
     * in that order and array_keys() keeps it. Two start pages of the same
     * language in different currencies are two rows and one language.
     *
     * @return list<string>
     */
    public function of(int $shopId): array
    {
        $languages = [];

        foreach ($this->audiencesOf($shopId) as $audience) {
            if ('' !== $audience['language']) {
                $languages[$audience['language']] = true;
            }
        }

        return array_keys($languages);
    }

    /**
     * Every way this shop is reached: its root pages' language together with
     * the currency that applies there (spec 4.0).
     *
     * One row per distinct pair — two domains in German and euro are one
     * audience, German in euro and German in francs are two.
     *
     * Nothing is dropped: a root page without a currency is reported with an
     * empty one, because whoever names that gap will need the row. Whoever
     * calculates skips it, the same division of labour as of() and the empty
     * language.
     *
     * ⚠️ That namer arrived with C-1c task 7 — and it does NOT read through
     * here. DbalShopScopeIssueSource queries tl_page itself, because a message
     * an operator can act on has to carry the page's title and id, and this
     * method returns neither. So no caller depends on the empty CURRENCY being
     * reported any more; both readers of audiencesOf() skip it (of() and
     * DbalCatalogCompletenessIssueSource::currenciesOf()), and the empty
     * LANGUAGE is still needed (VisibilityProjector::targets()).
     *
     * Left as it is rather than narrowed on the way past: dropping the row
     * would change what a documented, tested method returns, for no caller's
     * benefit. Reported instead of done, like the class name above.
     *
     * @return list<array{language: string, currency: string}>
     */
    public function audiencesOf(int $shopId): array
    {
        // The currency sits in the same row as the language, so it is one
        // field more and not a second query.
        return $this->audiences[$shopId] ??= array_map(
            static fn (array $row): array => ['language' => (string) $row['language'], 'currency' => (string) $row['currency']],
            $this->connection->fetchAllAssociative(
                "SELECT DISTINCT language, consho_currency AS currency FROM tl_page
                  WHERE type = 'root' AND consho_shop = ?
                  ORDER BY language, currency",
                [$shopId],
            ),
        );
    }

    /**
     * The languages that need a translation row — that is, all except the
     * default language, whose texts sit on the record itself.
     *
     * @return list<string>
     */
    public function translatedOf(int $shopId): array
    {
        $fallback = $this->fallbackOf($shopId);

        return array_values(array_filter($this->of($shopId), static fn (string $l): bool => $l !== $fallback));
    }

    /**
     * The stored default language — or, if none is set, the shop's first
     * language.
     *
     * The fallback is not a convenience feature: a freshly created shop
     * cannot carry a default language yet, because no root page points at it
     * and the selection is therefore empty. Without this fallback, every
     * language would be a translation language and the shop would be empty
     * from its first root page onward.
     */
    public function fallbackOf(int $shopId): string
    {
        return $this->fallbacks[$shopId] ??= $this->readFallback($shopId);
    }

    /**
     * A shop without a second language. The touchstone of the whole
     * specification: nothing may change for it.
     */
    public function isMonolingual(int $shopId): bool
    {
        return [] === $this->translatedOf($shopId);
    }

    /**
     * All translation languages of all shops.
     *
     * For master data that is not attached to a shop — product types,
     * manufacturers, properties. It applies installation-wide.
     *
     * Also for the version snapshot of a product, although a product IS
     * attached to shops: which shops it is in can change between two
     * versions, so a snapshot limited to today's shops would lose languages
     * on restore (TranslationVersioning).
     *
     * @return list<string>
     */
    public function allTranslated(): array
    {
        $shopIds = array_map(intval(...), $this->connection->fetchFirstColumn('SELECT id FROM tl_consho_shop'));
        $languages = [];

        foreach ($shopIds as $shopId) {
            $languages = [...$languages, ...$this->translatedOf($shopId)];
        }

        $languages = array_values(array_unique($languages));
        sort($languages);

        return $languages;
    }

    private function readFallback(int $shopId): string
    {
        $stored = (string) $this->connection->fetchOne('SELECT fallbackLanguage FROM tl_consho_shop WHERE id = ?', [$shopId]);

        return '' !== $stored ? $stored : ($this->of($shopId)[0] ?? '');
    }
}
