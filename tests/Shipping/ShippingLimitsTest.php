<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLimits;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingLine;

/**
 * E-5, V4: Grenzen je Stück — längste Seite, Gurtmaß, Gewicht. 0 heißt ohne
 * Grenze, ein Artikel ohne Maße passt.
 */
class ShippingLimitsTest extends TestCase
{
    public function testOhneGrenzenPasstAlles(): void
    {
        self::assertTrue((new ShippingLimits())->admits($this->piece('500', '500', '500', '1000')));
    }

    /**
     * Die längste Seite ist die längste, gleich in welchem Feld sie steht:
     * Ein Brett von 120 × 20 × 2, als „Breite 120" erfasst, bleibt 120 lang.
     */
    public function testDieLaengsteSeiteGleichInWelchemFeld(): void
    {
        $limits = new ShippingLimits('100.0000');

        self::assertFalse($limits->admits($this->piece('20', '120', '2')));
        self::assertTrue($limits->admits($this->piece('100', '20', '2')), 'genau 100 passt noch');
    }

    public function testGurtmassMitDerLaengstenSeiteAlsLaenge(): void
    {
        // 120 + 2×60 + 2×60 = 360; als 60 + 2×120 + 2×60 gerechnet wären es 420.
        $limits = new ShippingLimits('0.0000', '360.0000');

        self::assertTrue($limits->admits($this->piece('60', '120', '60')));
        self::assertFalse($limits->admits($this->piece('60', '121', '60')));
    }

    public function testGewichtJeStueck(): void
    {
        $limits = new ShippingLimits('0.0000', '0.0000', '31.5000');

        self::assertTrue($limits->admits($this->piece('0', '0', '0', '31.5')));
        self::assertFalse($limits->admits($this->piece('0', '0', '0', '31.6')));
    }

    public function testEinArtikelOhneMassePasst(): void
    {
        self::assertTrue((new ShippingLimits('10.0000', '20.0000', '1.0000'))->admits($this->piece('0', '0', '0', '0')));
    }

    public function testEinStueckDaruberGenuegt(): void
    {
        $limits = new ShippingLimits('100.0000');

        self::assertFalse($limits->admitAll([$this->piece('50', '10', '10'), $this->piece('150', '10', '10')]));
        self::assertTrue($limits->admitAll([]));
    }

    private function piece(string $length, string $width, string $height, string $weight = '1'): ShippingLine
    {
        return new ShippingLine(1, '1', '10.0000', 1, $weight, $length, $width, $height);
    }
}
