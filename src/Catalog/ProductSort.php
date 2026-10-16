<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * The orders a listing can be put in.
 *
 * Plain constants rather than an enum: the value travels through the URL and
 * through a DCA option list, and both want strings. An enum would be cast back
 * and forth at every boundary for no gain.
 */
final class ProductSort
{
    public const TITLE = 'title';
    public const PRICE_ASC = 'price_asc';
    public const PRICE_DESC = 'price_desc';
    public const NEWEST = 'newest';

    /** The editor's own order, tl_consho_product.sorting. */
    public const MANUAL = 'sorting';

    /** @var list<string> */
    public const ALL = [self::TITLE, self::PRICE_ASC, self::PRICE_DESC, self::NEWEST, self::MANUAL];

    /*
     * By id, oldest first — for the catalogue reader (Connect-1), whose feeds
     * must not reorder when somebody fixes a title. NOT in ALL: no visitor
     * and no editor can choose it, isKnown() says no.
     */
    public const ID = 'id';

    public static function isKnown(string $sort): bool
    {
        return \in_array($sort, self::ALL, true);
    }
}
