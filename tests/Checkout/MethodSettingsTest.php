<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;

class MethodSettingsTest extends TestCase
{
    public function testDieAuswahlBehaeltIhreReihenfolge(): void
    {
        // checkboxWizard speichert die IDs als Zeichenketten in der gezogenen Reihenfolge.
        self::assertSame([5, 3, 8], MethodSettings::selectedIds(serialize(['5', '3', '8'])));
    }

    public function testLeereUngueltigeUndDoppelteEintraegeFallenWeg(): void
    {
        self::assertSame([], MethodSettings::selectedIds(null));
        self::assertSame([], MethodSettings::selectedIds(''));
        self::assertSame([5, 3], MethodSettings::selectedIds(serialize(['5', '0', 'x', '3', '5'])));
    }

    public function testJsonDataWirdZumArray(): void
    {
        self::assertSame(['zone' => 'EU'], MethodSettings::decode('{"zone":"EU"}'));
        self::assertSame([], MethodSettings::decode(null));
        self::assertSame([], MethodSettings::decode('kaputt'));
        self::assertSame([], MethodSettings::decode('"nur ein Text"'));
    }

    public function testEineJsonListeGiltNichtAlsEinstellungen(): void
    {
        // '["a","b"]' dekodiert zu einem gültigen PHP-Array, aber ohne
        // Feldnamen — keine kaputte Config, nur nicht diese hier.
        self::assertSame([], MethodSettings::decode('["a","b"]'));
    }
}
