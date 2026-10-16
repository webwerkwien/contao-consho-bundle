<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductTile;

/**
 * Die Kachel bekommt Adresse und Anzeigepreis erst im Controller — das
 * Repository kennt weder die Produktseite noch die Sprache der Anzeige.
 *
 * withLink() kopiert also eine Kachel. Eine Kopie, die ein Feld verliert, fällt
 * erst im Browser auf: ein fehlendes „ab" sieht aus wie ein richtiger Preis.
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
        // Der rohe Betrag bleibt — für B-6 und für eigene Vorlagen im Template Studio.
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
     * 🔴 `withLink()` baut ein neues Objekt — jedes Feld, das dort vergessen
     * wird, fällt auf seinen Vorgabewert zurück. Für `isBuyable` hiesse das:
     * der Kaufknopf verschwindet, sobald die Kachel ihren Link bekommt, also
     * immer. Ein Feld mehr am Konstruktor ist genau der Moment, in dem das
     * passiert.
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
