<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Seo\ProductAddressState;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;

/**
 * Records the addresses that move when a version is restored: of a product
 * with a different alias, translated alias, sku, manufacturer or product
 * type; of a manufacturer or a product type with a different alias, own or
 * translated.
 *
 * ── Why a snapshot in onload ──────────────────────────────────────────────
 *
 * Contao 5.7, Versions::restore(): the UPDATE of the record comes first, then
 * tl_version is switched to the restored version, and only then
 * onrestore_version_callback is called. By then the database holds the
 * restored alias, and RedirectRecorder can no longer render the old address
 * from it.
 *
 * The previous version is no way back either: restore() has already moved the
 * `active` flag, and the newest version need not be the state that was
 * current — after restoring version 2 of 5, the record is version 2.
 *
 * So the aliases are read while they are still the old ones. DC_Table calls
 * the onload callbacks in its constructor; restore() runs later, in edit(),
 * for a POST with FORM_SUBMIT=tl_version. That request is what onLoad()
 * watches for. The recorder then receives the previous alias explicitly.
 *
 * A restore that does not come through the mask — a command, a script — finds
 * no snapshot and records nothing. Writes past the back end are a deliberate
 * boundary of the specification (4.4); a guessed old address would be worse
 * than none.
 *
 * ── Why a negative priority ───────────────────────────────────────────────
 *
 * TranslationVersionListener writes the translations of the version back in
 * its own onrestore_version callback. This one must see them afterwards.
 * Contao appends callbacks with priority ≤ 0 in descending order
 * (DataContainerCallbackListener::addCallbacks()), so -1 comes after 0.
 *
 * ── What it compares ──────────────────────────────────────────────────────
 *
 * For a product, every part of its address at once — alias, translations,
 * sku, manufacturer, product type (RedirectRecorder::recordProductAddress()).
 * A version that changes the alias and the manufacturer together is compared
 * with the complete previous address, not with one where either part is
 * already the restored one. The mask does the same (ProductAddressListener).
 *
 * For a manufacturer or product type, its aliases.
 */
class AliasRestoreListener
{
    private const TABLES = ['tl_consho_product', 'tl_consho_manufacturer', 'tl_consho_product_type'];

    /**
     * Aliases of manufacturers and product types per table and record, taken
     * before the restore.
     *
     * @var array<string, array<int, array{alias: string, translations: array<string, string>}>>
     */
    private array $snapshots = [];

    /**
     * Address state of products per record, taken before the restore.
     *
     * @var array<int, ProductAddressState>
     */
    private array $products = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly RedirectRecorder $recorder,
    ) {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onload')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        if (null === $dc) {
            return;
        }

        $table = (string) $dc->table;
        $id = (int) $dc->id;
        $request = $this->requests->getCurrentRequest();

        if (
            $id < 1
            || !\in_array($table, self::TABLES, true)
            || null === $request
            || 'tl_version' !== $request->request->get('FORM_SUBMIT')
            || !$request->request->get('version')
        ) {
            return;
        }

        if ('tl_consho_product' === $table) {
            $state = $this->recorder->productAddressState($id);

            if (null !== $state) {
                $this->products[$id] = $state;
            }

            return;
        }

        $this->snapshots[$table][$id] = $this->aliasesOf($table, $id);
    }

    /**
     * 🔴 The id comes from the parameter, NOT from $data: Versions::restore()
     * removes id, pid, ptable and sorting from $data before it calls back.
     *
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onrestore_version', priority: -1)]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onrestore_version', priority: -1)]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onrestore_version', priority: -1)]
    public function onRestoreVersion(string $table, int|string $id, int|string $version, array $data): void
    {
        $id = (int) $id;
        $now = time();

        if ('tl_consho_product' === $table) {
            $state = $this->products[$id] ?? null;
            unset($this->products[$id]);

            if (null !== $state) {
                $this->recorder->recordProductAddress($id, $state, $now);
            }

            return;
        }

        $before = $this->snapshots[$table][$id] ?? null;
        unset($this->snapshots[$table][$id]);

        if (null === $before) {
            return;
        }

        $after = $this->aliasesOf($table, $id);

        if ($before['alias'] !== $after['alias']) {
            $this->recorder->recordSegmentAlias($table, $id, $after['alias'], $now, $before['alias']);
        }

        $languages = array_unique([...array_keys($before['translations']), ...array_keys($after['translations'])]);

        foreach ($languages as $language) {
            $language = (string) $language;
            $previous = $before['translations'][$language] ?? '';
            $current = $after['translations'][$language] ?? '';

            if ($previous === $current) {
                continue;
            }

            $this->recorder->recordTranslatedSegmentAlias($table, $id, $language, $current, $now, $previous);
        }
    }

    /**
     * $table is one of TABLES, never input.
     *
     * @return array{alias: string, translations: array<string, string>}
     */
    private function aliasesOf(string $table, int $id): array
    {
        $translations = [];

        foreach ($this->connection->fetchAllAssociative(
            'SELECT language, alias FROM tl_consho_translation WHERE ptable = :ptable AND pid = :pid',
            ['ptable' => $table, 'pid' => $id],
        ) as $row) {
            $translations[(string) $row['language']] = (string) $row['alias'];
        }

        return [
            'alias' => (string) $this->connection->fetchOne(\sprintf('SELECT alias FROM %s WHERE id = :id', $table), ['id' => $id]),
            'translations' => $translations,
        ];
    }
}
