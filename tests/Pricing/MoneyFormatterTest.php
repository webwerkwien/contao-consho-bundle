<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Brick\Math\Exception\NumberFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The table of cases is the contract (B-5).
 *
 * Depending on the version, ICU puts U+00A0 or U+202F as the space between amount
 * and currency sign. The comparison is therefore made only after both have been
 * replaced by a normal space — otherwise the test would depend on the machine's ICU version
 * instead of on the rounding.
 */
class MoneyFormatterTest extends TestCase
{
    private MoneyFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new MoneyFormatter();
    }

    #[DataProvider('amounts')]
    public function testFormatsAnAmountTheWayABuyerReadsIt(string $amount, string $currency, string $language, string $expected): void
    {
        self::assertSame($expected, self::plainSpaces($this->formatter->format($amount, $currency, $language)));
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function amounts(): iterable
    {
        yield 'Dezimalzeichenkette aus MySQL' => ['13.0000', 'EUR', 'de', '13,00 €'];

        // As a float 12.605 cannot be represented, and without our own
        // rounding ICU rounds commercially wrong: half to even, so 12.60.
        yield 'kaufmännisch aufgerundet' => ['12.605', 'EUR', 'de', '12,61 €'];

        yield 'englische Schreibweise' => ['2.675', 'EUR', 'en', '€2.68'];

        // 2.675 would stay right even without our own rounding, because an
        // odd digit precedes the 5 and ICU rounds half to even. Only an
        // even digit before it shows the difference.
        yield 'aufgerundet trotz gerader Ziffer davor' => ['2.665', 'EUR', 'en', '€2.67'];

        yield 'Tausendertrennzeichen' => ['1234567.5', 'EUR', 'de', '1.234.567,50 €'];
        yield 'null Nachkommastellen aus Currencies' => ['1500', 'JPY', 'en', '¥1,500'];
        yield 'null' => ['0', 'EUR', 'de', '0,00 €'];
    }

    public function testAcceptsALanguageTagWithAHyphen(): void
    {
        $expected = (new \NumberFormatter('de_AT', \NumberFormatter::CURRENCY))->formatCurrency(1234.50, 'CHF');

        self::assertSame(self::plainSpaces((string) $expected), self::plainSpaces($this->formatter->format('1234.5', 'CHF', 'de-AT')));
    }

    public function testFallsBackToEnglishWithoutALanguage(): void
    {
        self::assertSame(
            self::plainSpaces($this->formatter->format('13', 'EUR', 'en')),
            self::plainSpaces($this->formatter->format('13', 'EUR', '')),
        );
    }

    public function testABrokenAmountIsAnErrorRatherThanADisplay(): void
    {
        // A broken amount must not silently appear as €0.00.
        $this->expectException(NumberFormatException::class);

        $this->formatter->format('abc', 'EUR', 'de');
    }

    #[DataProvider('quantities')]
    public function testFormatsAQuantityWithoutTrailingZeros(string $amount, string $language, string $expected): void
    {
        self::assertSame($expected, self::plainSpaces($this->formatter->quantity($amount, $language)));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function quantities(): iterable
    {
        yield 'ganze Zahl aus MySQL' => ['1.0000', 'de', '1'];
        yield 'Staffelschwelle' => ['100.0000', 'de', '100'];
        yield 'Dezimalkomma' => ['2.5000', 'de', '2,5'];
        yield 'Dezimalpunkt' => ['0.2500', 'en', '0.25'];

        // NumberFormatter cuts off after three decimals by itself; a
        // quantity, however, is not rounded but shown as it is stored.
        yield 'vier Nachkommastellen bleiben stehen' => ['0.0001', 'de', '0,0001'];

        yield 'Tausendertrennzeichen' => ['1000', 'de', '1.000'];
        yield 'leere Sprache fällt auf en' => ['2.5', '', '2.5'];
    }

    public function testABrokenQuantityIsAnError(): void
    {
        $this->expectException(NumberFormatException::class);

        $this->formatter->quantity('', 'de');
    }

    private static function plainSpaces(string $formatted): string
    {
        return str_replace(["\u{00A0}", "\u{202F}"], ' ', $formatted);
    }
}
