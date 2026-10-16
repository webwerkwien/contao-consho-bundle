<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * The three URL classes of a product listing.
 *
 * Category and FacetPage are path segments and get indexed. Parameter is the
 * excluded query parameter — the combinatorics live there and stay out of the
 * index.
 */
enum UrlClass
{
    case Category;
    case FacetPage;
    case Parameter;
}
