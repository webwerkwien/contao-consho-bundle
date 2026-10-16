<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One slice of the visibility projection: a shop, in one language, priced in
 * one currency.
 *
 * That is the unit VisibilityProjector deletes, writes and compares in one
 * transaction, and since C-1c task 5b it is also what
 * tl_consho_product_visibility is unique on — together with the article.
 *
 * `fallback` and `country` ride along because every statement of a target
 * needs them and neither belongs to the target itself: the default language is
 * the shop's (it decides which products are visible untranslated), the home
 * country likewise (it decides the gross of every "from" price, C25). The
 * currency is the audience's, the country the invoicing entity's — spec 4.0,
 * the same split MarketCriteria states for the list prices.
 *
 * ── Why a class and not an array shape ───────────────────────────────────
 *
 * The shape stood written out seven times in VisibilityProjector, and adding
 * the currency in task 5b meant touching all seven. Same reasoning as Market,
 * and the same house rule VisibilityCriteria was built on: four copies of one
 * definition are three too many.
 */
final class VisibilityTarget
{
    public function __construct(
        public readonly int $shop,
        public readonly string $language,
        public readonly string $currency,
        public readonly string $fallback,
        public readonly string $country,
    ) {
    }

    /**
     * The target as an array key.
     *
     * The separator is a null byte, which no language tag and no currency code
     * contains — so two different targets cannot spell the same key.
     */
    public function key(): string
    {
        return $this->shop."\0".$this->language."\0".$this->currency;
    }

    /**
     * The key of a stored row, without building a target for it.
     *
     * Used where the table is compared against the targets: a row names its
     * shop, language and currency, and nothing else of a target.
     */
    public static function keyOf(int $shop, string $language, string $currency): string
    {
        return $shop."\0".$language."\0".$currency;
    }
}
