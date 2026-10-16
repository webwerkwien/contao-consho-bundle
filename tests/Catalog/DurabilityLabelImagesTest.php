<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Image\Studio\Figure;
use Contao\CoreBundle\Image\Studio\FigureBuilder;
use Contao\CoreBundle\Image\Studio\ImageResult;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityGuarantees;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;

/**
 * Block GARAN, GA3: das Label als Bild — unverkleinert, eine gelöschte Datei
 * fällt still weg, ein Label für zwei Artikel wird einmal gebaut.
 */
class DurabilityLabelImagesTest extends TestCase
{
    public function testBautDasBildEinmalJeLabel(): void
    {
        $label = StringUtil::binToUuid(str_repeat("\x11", 16));
        $gone = StringUtil::binToUuid(str_repeat("\x22", 16));

        $guarantees = $this->createMock(DurabilityGuarantees::class);
        $guarantees->method('of')->with([1, 2, 3])->willReturn([
            1 => ['years' => '5', 'label' => $label, 'nested' => null],
            2 => ['years' => '5', 'label' => $label, 'nested' => null],
            3 => ['years' => '4', 'label' => $gone, 'nested' => null],
        ]);

        $image = $this->createMock(ImageResult::class);
        $image->method('getImg')->willReturn(['src' => 'files/garan/akkuschrauber.png', 'width' => 300, 'height' => 316]);

        $builder = $this->createMock(FigureBuilder::class);
        $builder->expects(self::exactly(2))->method('fromUuid')->willReturnCallback(function (string $uuid) use ($label, $image): FigureBuilder {
            $clone = $this->createMock(FigureBuilder::class);
            $clone->method('buildIfResourceExists')->willReturn($uuid === $label ? new Figure($image) : null);

            return $clone;
        });

        $studio = $this->createMock(Studio::class);
        $studio->method('createFigureBuilder')->willReturn($builder);

        self::assertSame(
            [
                1 => ['years' => '5', 'info' => DurabilityGuarantees::INFO_URL, 'src' => 'files/garan/akkuschrauber.png', 'width' => 300, 'height' => 316],
                2 => ['years' => '5', 'info' => DurabilityGuarantees::INFO_URL, 'src' => 'files/garan/akkuschrauber.png', 'width' => 300, 'height' => 316],
            ],
            (new DurabilityLabelImages($guarantees, $studio))->of([1, 2, 3]),
        );
    }

    /**
     * GA8: Für die Kacheln nur Artikel mit geschachteltem UND vollem Bild —
     * das geschachtelte verlinkt aufs volle.
     */
    public function testGeschachteltNurMitBeidenBildern(): void
    {
        $label = StringUtil::binToUuid(str_repeat("\x11", 16));
        $nested = StringUtil::binToUuid(str_repeat("\x33", 16));
        $gone = StringUtil::binToUuid(str_repeat("\x22", 16));

        $guarantees = $this->createMock(DurabilityGuarantees::class);
        $guarantees->method('of')->with([1, 2, 3, 4])->willReturn([
            1 => ['years' => '2.5', 'label' => $label, 'nested' => $nested],
            2 => ['years' => '5', 'label' => $label, 'nested' => null],
            3 => ['years' => '5', 'label' => $label, 'nested' => $gone],
            4 => ['years' => '5', 'label' => $gone, 'nested' => $nested],
        ]);

        $images = [$label => ['src' => 'files/garan/voll.png', 'width' => 300, 'height' => 316], $nested => ['src' => 'files/garan/klein.svg', 'width' => 90, 'height' => 40]];

        $builder = $this->createMock(FigureBuilder::class);
        $builder->method('fromUuid')->willReturnCallback(function (string $uuid) use ($images): FigureBuilder {
            $image = $this->createMock(ImageResult::class);
            $image->method('getImg')->willReturn($images[$uuid] ?? []);

            $clone = $this->createMock(FigureBuilder::class);
            $clone->method('buildIfResourceExists')->willReturn(isset($images[$uuid]) ? new Figure($image) : null);

            return $clone;
        });

        $studio = $this->createMock(Studio::class);
        $studio->method('createFigureBuilder')->willReturn($builder);

        self::assertSame(
            [1 => ['years' => '2.5', 'info' => DurabilityGuarantees::INFO_URL, 'src' => 'files/garan/voll.png', 'width' => 300, 'height' => 316, 'nested' => ['src' => 'files/garan/klein.svg', 'width' => 90, 'height' => 40]]],
            (new DurabilityLabelImages($guarantees, $studio))->nestedOf([1, 2, 3, 4]),
        );
    }

    /**
     * GA6: Die Bestellseite zeigt, was an den Positionen eingefroren ist —
     * das heutige Produkt wird nicht gefragt.
     */
    public function testDieEingefrorenenPositionen(): void
    {
        $label = str_repeat("\x11", 16);
        $guarantees = $this->createMock(DurabilityGuarantees::class);
        $guarantees->expects(self::never())->method('of');

        $image = $this->createMock(ImageResult::class);
        $image->method('getImg')->willReturn(['src' => 'files/garan.png', 'width' => 300, 'height' => 316]);

        $builder = $this->createMock(FigureBuilder::class);
        $builder->expects(self::once())->method('fromUuid')->with(StringUtil::binToUuid($label))->willReturnSelf();
        $builder->method('buildIfResourceExists')->willReturn(new Figure($image));

        $studio = $this->createMock(Studio::class);
        $studio->method('createFigureBuilder')->willReturn($builder);

        self::assertSame(
            [
                ['name' => 'Akkuschrauber & Bits', 'years' => '5', 'info' => DurabilityGuarantees::INFO_URL, 'src' => 'files/garan.png', 'width' => 300, 'height' => 316],
                ['name' => 'Bohrer', 'years' => '5', 'info' => DurabilityGuarantees::INFO_URL, 'src' => 'files/garan.png', 'width' => 300, 'height' => 316],
            ],
            (new DurabilityLabelImages($guarantees, $studio))->ofOrderItems([
                ['lineType' => 'product', 'productName' => 'Akkuschrauber &amp; Bits', 'durabilityYears' => 5, 'durabilityLabel' => $label],
                ['lineType' => 'product', 'productName' => 'Bohrer', 'durabilityYears' => '5', 'durabilityLabel' => $label],
                ['lineType' => 'product', 'productName' => 'Seil', 'durabilityYears' => 0, 'durabilityLabel' => null],
                // Review GARAN, 4: zwei Jahre sind keine Garantie im Sinne von Z 12a.
                ['lineType' => 'product', 'productName' => 'Zelt', 'durabilityYears' => 2, 'durabilityLabel' => $label],
                ['lineType' => 'shipping', 'productName' => 'Paket', 'durabilityYears' => 5, 'durabilityLabel' => $label],
            ]),
        );
    }
}
