<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use Brick\Math\Exception\NumberFormatException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * Die Tabelle aus Task 4 des B-5-Plans ist der Vertrag.
 *
 * ICU setzt je nach Version U+00A0 oder U+202F als Leerzeichen zwischen Betrag
 * und Währungszeichen. Verglichen wird deshalb erst, nachdem beide durch ein
 * normales Leerzeichen ersetzt sind — sonst hinge der Test an der ICU-Version
 * der Maschine statt an der Rundung.
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

        // Als Float ist 12.605 nicht darstellbar, und ICU rundet ohne eigene
        // Rundung kaufmännisch falsch: halb auf gerade, also 12,60.
        yield 'kaufmännisch aufgerundet' => ['12.605', 'EUR', 'de', '12,61 €'];

        yield 'englische Schreibweise' => ['2.675', 'EUR', 'en', '€2.68'];

        // 2.675 bliebe auch ohne eigene Rundung richtig, weil vor der 5 eine
        // ungerade Ziffer steht und ICU halb auf gerade rundet. Erst eine
        // gerade Ziffer davor zeigt den Unterschied.
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
        // Ein kaputter Betrag darf nicht still als 0,00 € erscheinen.
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

        // NumberFormatter schneidet von sich aus nach drei Stellen ab; eine
        // Menge wird aber nicht gerundet, sondern gezeigt, wie sie gespeichert ist.
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
