<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\GuaranteeYears;

/**
 * Block GARAN, GA9: whole or half years (Commission guidelines, 3.1 vi).
 */
class GuaranteeYearsTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function inputs(): iterable
    {
        yield 'ganz' => ['5', '5'];
        yield 'zweistellig' => ['12', '12'];
        yield 'Komma' => ['2,5', '2.5'];
        yield 'Punkt' => ['4.5', '4.5'];
        yield 'MySQL ganz' => ['5.0', '5'];
        yield 'MySQL halb' => ['2.50', '2.5'];
        yield 'führende Null' => ['05', '5'];
        yield 'Leerzeichen' => [' 3 ', '3'];
        yield 'leer' => ['', '0'];
        yield 'null' => [null, '0'];
        yield 'Zahl' => [3, '3'];
        yield 'andere Kommastelle' => ['4,2', null];
        yield 'Viertel' => ['2.25', null];
        yield 'negativ' => ['-3', null];
        yield 'dreistellig' => ['100', null];
        yield 'Text' => ['drei', null];
    }

    #[DataProvider('inputs')]
    public function testParse(mixed $input, string|null $expected): void
    {
        self::assertSame($expected, GuaranteeYears::parse($input));
    }

    public function testMehrAlsZweiJahre(): void
    {
        self::assertFalse(GuaranteeYears::qualifies('2'));
        self::assertTrue(GuaranteeYears::qualifies('2.5'));
        self::assertTrue(GuaranteeYears::qualifies('3'));
        self::assertSame('0', GuaranteeYears::of('4.2'), 'unlesbar zählt als keine');
    }

    public function testAnzeige(): void
    {
        self::assertSame('2,5', GuaranteeYears::display('2.5', ','));
        self::assertSame('2.5', GuaranteeYears::display('2.5', '.'));
        self::assertSame('5', GuaranteeYears::display('5', ','));
    }
}
