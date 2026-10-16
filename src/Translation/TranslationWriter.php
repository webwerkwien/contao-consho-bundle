<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Translation;

use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasScope;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;

/**
 * Writes a language block of a translated entity with the rules of its mask
 * (docs/mehrsprachigkeit.md, 7.3): merged over what is stored, an alias
 * generated from the title, old addresses handed to the redirects.
 *
 * Two callers. The mask (TranslationFieldsListener) uses the parts —
 * checkAlias() per field, store() on submit — because it collects a block
 * field by field. A console command (Connect Stufe 2, CW-2) uses set(), which
 * also checks what a form would have fixed: the language and the fields.
 *
 * ⚠️ Writing a block creates no version of the parent. The mask sets
 * DC_Table's flag when store() reports a change; any other caller has to see
 * to the version itself.
 */
class TranslationWriter
{
    public function __construct(
        private readonly TranslationStorage $storage,
        private readonly AliasFactory $aliases,
        private readonly RedirectRecorder $recorder,
        private readonly ShopLanguages $languages,
    ) {
    }

    /**
     * Checks and writes one block. Fields not given keep their stored value;
     * an empty string clears one.
     *
     * @param array<string, string> $values field => value
     *
     * @throws TranslationRefused
     *
     * @return bool whether the stored block changed
     */
    public function set(string $table, int $id, string $language, array $values): bool
    {
        $fields = TranslatableFields::of($table);

        if ([] === $fields) {
            throw new TranslationRefused(\sprintf('%s has no translatable fields.', $table));
        }

        if ($id < 1) {
            throw new TranslationRefused('A translation needs a saved record.');
        }

        if (!\in_array($language, $this->languagesOf($table, $id), true)) {
            throw new TranslationRefused(\sprintf('"%s" is no translation language of %s %d. Offered: %s.', $language, $table, $id, implode(', ', $this->languagesOf($table, $id)) ?: 'none'));
        }

        // The custom field texts are checked one by one, per field and type
        // (CustomFieldTranslations) — never written here as raw JSON.
        $settable = array_values(array_diff($fields, ['customFieldValues']));
        $unknown = array_diff(array_keys($values), $settable);

        if ([] !== $unknown) {
            throw new TranslationRefused(\sprintf('Not translatable in %s: %s. Translatable: %s.%s', $table, implode(', ', $unknown), implode(', ', $settable), $settable !== $fields ? ' Custom field texts are set per field.' : ''));
        }

        if (\in_array('alias', $fields, true)) {
            $values['alias'] = trim($values['alias'] ?? $this->storage->read($table, $id, $language, 'alias'));

            try {
                $this->checkAlias($table, $id, $language, $values['alias'], $values);
            } catch (\RuntimeException $e) {
                throw new TranslationRefused($e->getMessage(), 0, $e);
            }
        }

        return $this->store($table, $id, $language, $values);
    }

    /**
     * Refuses a typed alias that is numeric or taken, and an empty one where
     * the language block has content but no title to generate it from.
     *
     * @param array<string, string> $submitted the block's other values known so far
     *
     * @throws \RuntimeException with a message for the editor
     */
    public function checkAlias(string $table, int $id, string $language, string $alias, array $submitted): void
    {
        if ('' !== $alias) {
            // The id to exclude is the translation row's, not the parent's:
            // the check runs against tl_consho_translation.
            $this->aliases->forRecord(
                'tl_consho_translation',
                $alias,
                '',
                $this->storage->rowId($table, $id, $language),
                $this->scope($table, $language),
            );

            return;
        }

        $block = [
            ...$this->storage->readAll($table, $id, $language),
            ...$submitted,
            'alias' => '',
        ];

        if ($this->hasContent($block) && '' === ($block['title'] ?? '')) {
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['translationAliasRequired'] ?? 'The alias for "%s" is required.', $language));
        }
    }

    /**
     * Writes the block over the stored one, without checks.
     *
     * The stored block first, the given values over it: write() replaces the
     * WHOLE block, and a field that was not given — another palette state, a
     * field selection in "edit multiple", a command naming one field — would
     * otherwise be cleared without anyone having touched it.
     *
     * @param array<string, string> $values
     *
     * @return bool whether the stored block changed
     */
    public function store(string $table, int $id, string $language, array $values): bool
    {
        $stored = $this->storage->readAll($table, $id, $language);
        $block = [...$stored, ...$values];

        // Specification 7.3: a filled title and an empty alias → generate,
        // the same mechanism as the main alias. A block without a title was
        // refused in checkAlias() already; reaching this point without one
        // means the alias was not given, and the row is written as it is —
        // without an alias it stays invisible (section 5).
        if (
            $this->hasContent($block)
            && \in_array('alias', TranslatableFields::of($table), true)
            && '' === trim($block['alias'] ?? '')
            && '' !== ($block['title'] ?? '')
        ) {
            $block['alias'] = $this->aliases->forRecord(
                'tl_consho_translation',
                '',
                $block['title'],
                $this->storage->rowId($table, $id, $language),
                $this->scope($table, $language),
            );
        }

        // Before write() — see rememberOldAddress().
        $this->rememberOldAddress(
            $table,
            $id,
            $language,
            $stored['alias'] ?? '',
            $this->hasContent($block) ? trim($block['alias'] ?? '') : '',
        );

        $this->storage->write($table, $id, $language, $block);

        // Compared as stored, not as given: write() turns an empty alias into
        // NULL and an empty block into no row.
        return $this->storage->readAll($table, $id, $language) !== $stored;
    }

    /**
     * The translation languages a record offers.
     *
     * A product offers the languages of the shops it is in; a variant with no
     * shops of its own those of its main product (specification A, 3.7). A
     * shop translates into its OWN languages (C-8). Everything else — types,
     * manufacturers, properties — is not attached to a shop and offers every
     * translation language.
     *
     * @param int|null $pid a product's parent, when the caller has it; looked
     *                      up otherwise
     *
     * @return list<string>
     */
    public function languagesOf(string $table, int $id, int|null $pid = null): array
    {
        if ('tl_consho_shop' === $table) {
            return $this->languages->translatedOf($id);
        }

        if ('tl_consho_product' !== $table) {
            return $this->languages->allTranslated();
        }

        $shops = $this->storage->shopsOfProduct($id);

        if ([] === $shops) {
            $shops = $this->storage->shopsOfProduct($pid ?? $this->storage->parentOfProduct($id));
        }

        $languages = [];

        foreach ($shops as $shopId) {
            $languages = [...$languages, ...$this->languages->translatedOf($shopId)];
        }

        $languages = array_values(array_unique($languages));
        sort($languages);

        return $languages;
    }

    /**
     * Hands the addresses a manufacturer's or product type's products had in
     * this language to RedirectRecorder.
     *
     * 🔴 Before write(), not after: the recorder renders the old address from
     * the stored translation. Afterwards that is the new alias, and it would
     * record nothing.
     *
     * Here in store() and not in a field check: an alias left empty is
     * generated in store(), so only store() knows which alias the record ends
     * up with.
     *
     * An empty alias on either side is not skipped: without a translated alias
     * the address shows the record's own, so adding or removing the
     * translation moves it. The recorder resolves that fallback. Other tables
     * have no alias in a product address; the recorder ignores them.
     *
     * NOT for a product: in the mask this runs in onsubmit, after the UPDATE of
     * its record. A manufacturer or sku changed in the same save would already
     * be the new one, and the "old" address would never have existed.
     * ProductAddressListener compares the product's whole state instead.
     */
    private function rememberOldAddress(string $table, int $id, string $language, string $stored, string $alias): void
    {
        if ($stored === $alias || 'tl_consho_product' === $table) {
            return;
        }

        $this->recorder->recordTranslatedSegmentAlias($table, $id, $language, $alias, time());
    }

    /**
     * Unique per parent table AND language — the real index (ptable, language,
     * alias). A manufacturer and a product may share an alias; two English
     * products may not.
     */
    private function scope(string $table, string $language): AliasScope
    {
        return new AliasScope('ptable', $table, 'language', $language);
    }

    /**
     * Whether the block asks for an alias. Custom field texts do not (ZW-3):
     * they translate values on a page that exists only with a title, and a
     * block holding nothing else is invisible without asking for one — the
     * same as before they existed. Otherwise a single translated text made the
     * record unsavable from the second save on.
     *
     * @param array<string, string> $block
     */
    private function hasContent(array $block): bool
    {
        unset($block['customFieldValues']);

        return [] !== array_filter($block, static fn (string $value): bool => '' !== $value);
    }
}
