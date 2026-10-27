<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\ListingPageRepository;
use Webwerkwien\ContaoConshoBundle\Seo\FilterState;
use Webwerkwien\ContaoConshoBundle\Seo\FilterStateCodec;

/**
 * Normalises the filter combination an editor types into the canonical form.
 *
 * ⚠️ Without this the unique index on pid,filterState is decoration. The same
 * combination written "volume:250ml;colour:red" and "colour:red;volume:250ml"
 * would pass as two different rows — two indexed landing pages with identical
 * content, which is exactly what this table exists to prevent.
 *
 * The field stays a plain text field on purpose: at this point in Block A there
 * is no filter UI to pick from, and the combinations come from the property
 * groups of A-2. What the editor types is therefore checked, not trusted.
 *
 * Whitespace is stripped before decoding. The codec is deliberately strict
 * because it also reads public URLs; a backend field that rejected "volume:250ml
 * ; colour:red" over a space would only teach editors to distrust it.
 *
 * ── Checked against the properties and the listing page ───────────────────
 *
 * The front end drops what it cannot resolve (FilterResolver). A landing page
 * whose combination is dropped that way would show the whole category under
 * its own title, so on save every option must exist in a filterable group, the
 * page must carry a listing that follows the address, and the combination must
 * lie within that listing's preset. See Block B, 4.5.
 *
 * The combination must also be narrower than that preset
 * — one that matches it exactly would show the very same products as the
 * category page itself and earns no address of its own.
 */
class FacetStateListener
{
    public function __construct(
        private readonly FilterStateCodec $codec,
        private readonly Connection $connection,
        private readonly FilterResolver $filters,
        private readonly ListingPageRepository $listings,
    ) {
    }

    #[AsCallback(table: 'tl_consho_facet_page', target: 'fields.filterState.save')]
    public function __invoke(mixed $value, DC_Table $dc): string
    {
        $typed = trim((string) $value);
        $state = $this->codec->decode(preg_replace('/\s+/', '', $typed));

        if ('' !== $state->sort) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetStateSorted'], $typed),
            );
        }

        // Block Verfügbarkeit, V5: a view by stock is never a landing page.
        // Said, not silently dropped by the FilterState below.
        if ($state->availableOnly) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetStateAvailable'], $typed),
            );
        }

        // An option typed twice would be stored as "colour:red,red" — an address
        // the filter links never produce, so the landing page could not be hit.
        $state = new FilterState(array_map(
            static fn (array $options): array => array_values(array_unique($options)),
            $state->filters,
        ));

        $normalised = $this->codec->encode($state);

        if (null === $normalised) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetStateEmpty'], $typed),
            );
        }

        /*
         * Resolves completely or not at all. resolve() drops unknown options and
         * groups that are not filterable, so fewer ids than aliases means the
         * front end would ignore part of the combination.
         */
        $resolved = $this->filters->resolve($state);

        if ($this->count($resolved) !== $this->count($state->filters)) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetStateUnknown'], $normalised),
            );
        }

        // getActiveRecord(), not getCurrentRecord(): on a new record the stored
        // pid is still 0, and the page the editor just picked exists only in
        // the submitted values. Only the active record merges the two.
        $pageId = (int) ($dc->getActiveRecord()['pid'] ?? 0);

        // No page chosen yet: pid is mandatory and reports that itself.
        if ($pageId > 0) {
            $this->refuseOutsideTheListing($pageId, $resolved, $normalised);
        }

        /*
         * The unique index would catch this too — with a 500 and a raw SQL
         * message. Asking first turns it into a sentence that names the page
         * already holding the combination, which is what the editor needs: the
         * two entries look nothing alike before normalisation.
         */
        $existing = $this->connection->fetchOne(
            'SELECT alias FROM tl_consho_facet_page WHERE pid = :pid AND filterState = :state AND id != :id',
            [
                'pid' => $pageId,
                'state' => $normalised,
                'id' => (int) $dc->id,
            ],
        );

        if (false !== $existing) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetStateDuplicate'], $existing),
            );
        }

        return $normalised;
    }

    /**
     * @param array<int, list<int>> $resolved
     */
    private function refuseOutsideTheListing(int $pageId, array $resolved, string $normalised): void
    {
        /*
         * The same question the front end asks: the first visible listing that
         * follows the address. It also catches a record from before B-4, whose
         * pid still holds a shop id and now names an unrelated page.
         */
        $listing = $this->listings->firstOnPage($pageId, time());

        if (null === $listing) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetPageWithoutListing'], $pageId),
            );
        }

        $preset = $this->filters->group($listing->preset);

        if (null === $this->filters->narrow($preset, $resolved)) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetStateOutsideCategory'], $normalised),
            );
        }

        $this->refuseMatchingTheCategory($preset, $resolved, $normalised);
    }

    /**
     * A landing page whose combination is exactly the
     * category's own preset would show the same products as the category
     * itself — there is nothing left for it to narrow down.
     *
     * The question is "does the combination narrow the category at all", and
     * it is answered on ids, through the same narrow() the front end uses:
     * if narrowing the preset by the combination leaves the preset unchanged,
     * the landing page shows exactly the category.
     *
     * ⚠️ Not by comparing the preset and the combination as encoded strings.
     * A preset may use a group nobody can filter by ("Aktion", see
     * FilterResolver::group()). The editor cannot type that group, so the
     * strings never match — and the check would silently never fire for
     * exactly those categories. Narrowing carries such a group along instead.
     *
     * @param array<int, list<int>> $preset
     * @param array<int, list<int>> $resolved
     */
    private function refuseMatchingTheCategory(array $preset, array $resolved, string $normalised): void
    {
        // No preset: nothing to compare, e.g. a category built without one.
        if ([] === $preset) {
            return;
        }

        $narrowed = $this->filters->narrow($preset, $resolved);

        if (null !== $narrowed && self::sorted($narrowed) === self::sorted($preset)) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['facetStateMatchesCategory'], $normalised),
            );
        }
    }

    /**
     * Groups and options in a fixed order, so that the order the preset was
     * picked in and the order the database returns never decide the answer.
     *
     * @param array<int, list<int>> $groups
     *
     * @return array<int, list<int>>
     */
    private static function sorted(array $groups): array
    {
        ksort($groups);

        foreach ($groups as &$optionIds) {
            sort($optionIds);
        }

        return $groups;
    }

    /**
     * @param array<array-key, list<int|string>> $groups
     */
    private function count(array $groups): int
    {
        return array_sum(array_map(\count(...), $groups));
    }
}
