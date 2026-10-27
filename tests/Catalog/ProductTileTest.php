<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;

/**
 * The tile gets its address and display price only in the controller — the
 * repository knows neither the product page nor the display language.
 *
 * withLink() therefore copies a tile. A copy that loses a field only shows
 * in the browser: a missing "ab" looks like a correct price.
 */
class ProductTileTest extends TestCase
{
    public function testWithLinkKeepsEverythingTheListingRead(): void
    {
        $tile = new ProductTile(7, 'Schwedenbitter &amp; Kräuter', 'Nach alter Rezeptur', 'uuid-1', '12.6000', isFrom: true);

        $linked = $tile->withLink('/produkt/schwedenbitter.html', '12,60 €');

        self::assertSame(7, $linked->id);
        self::assertSame('Schwedenbitter &amp; Kräuter', $linked->title);
        self::assertSame('Nach alter Rezeptur', $linked->teaser);
        self::assertSame('uuid-1', $linked->image);
        // The raw amount stays — for B-6 and for custom templates in the Template Studio.
        self::assertSame('12.6000', $linked->price);
        self::assertTrue($linked->isFrom);
        self::assertSame('/produkt/schwedenbitter.html', $linked->url);
        self::assertSame('12,60 €', $linked->formattedPrice);
    }

    public function testWithLinkLeavesTheOriginalAlone(): void
    {
        $tile = new ProductTile(7, 'Schwedenbitter', '', '', '12.6000');

        $tile->withLink('/produkt/schwedenbitter.html', '12,60 €');

        self::assertSame('', $tile->url);
        self::assertSame('', $tile->formattedPrice);
    }

    /**
     * 🔴 `withLink()` builds a new object — every field forgotten there
     * falls back to its default value. For `isBuyable` that would mean:
     * the buy button disappears as soon as the tile gets its link, so
     * always. One more field on the constructor is exactly the moment this
     * happens.
     */
    public function testWithLinkVerliertKeinFeld(): void
    {
        $tile = new ProductTile(
            id: 7,
            title: 'Kräutersalz',
            teaser: 'Steirisch',
            image: 'bild.jpg',
            price: '8.9000',
            isFrom: true,
            isBuyable: true,
            purchaseQuantity: '2.5',
        );

        $verlinkt = $tile->withLink('/produkt/kraeutersalz.html', '8,90 €');

        self::assertTrue($verlinkt->isBuyable);
        self::assertSame('2.5', $verlinkt->purchaseQuantity);
        self::assertTrue($verlinkt->isFrom);
        self::assertSame('Kräutersalz', $verlinkt->title);
        self::assertSame('Steirisch', $verlinkt->teaser);
        self::assertSame('bild.jpg', $verlinkt->image);
        self::assertSame('8.9000', $verlinkt->price);
        self::assertSame('/produkt/kraeutersalz.html', $verlinkt->url);
        self::assertSame('8,90 €', $verlinkt->formattedPrice);
    }
}
