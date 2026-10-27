<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;

class MethodSettingsTest extends TestCase
{
    public function testDieAuswahlBehaeltIhreReihenfolge(): void
    {
        // checkboxWizard stores the IDs as strings in the order they were dragged into.
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
        // '["a","b"]' decodes to a valid PHP array, but without
        // field names — not a broken config, just not this one.
        self::assertSame([], MethodSettings::decode('["a","b"]'));
    }
}
