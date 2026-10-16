<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shipping;

/**
 * Weight and measurements of articles, as shipping sees them (E-4, V1), and
 * whether they are shipped at all (F-1, F13).
 */
interface ArticleShippingDataInterface
{
    /**
     * Per article id, per PIECE: weight in kg, length, width and height in cm,
     * each a 4-place string, '0.0000' where nothing is known.
     *
     * `kind`: the ProductKind value of the article's type — only a
     * physical good has to be shipped. An article without a product type
     * counts as physical: calling something digital that is not would skip
     * shipping for a parcel.
     *
     * @param list<int> $articleIds
     *
     * @return array<int, array{weight: string, length: string, width: string, height: string, kind: string}>
     */
    public function of(array $articleIds): array;
}
