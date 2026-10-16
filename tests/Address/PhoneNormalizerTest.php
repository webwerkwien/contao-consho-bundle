<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Address;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Address\PhoneNormalizer;

class PhoneNormalizerTest extends TestCase
{
    private PhoneNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new PhoneNormalizer();
    }

    #[DataProvider('austrianForms')]
    public function testAllCommonAustrianFormsBecomeTheSameNumber(string $input): void
    {
        self::assertSame('+436641234567', $this->normalizer->normalize($input, 'AT'));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function austrianForms(): iterable
    {
        yield ['0664 1234567'];
        yield ['0664/1234567'];
        yield ['0664-123 45 67'];
        yield ['+43 664 1234567'];
        yield ['+43 (0) 664 1234567'];
        yield ['0043 664 1234567'];
        yield ['0043-664-1234567'];
        yield ['  0664 1234567  '];
    }

    public function testGermanNumbersUseTheGermanPrefix(): void
    {
        self::assertSame('+4915112345678', $this->normalizer->normalize('0151 12345678', 'DE'));
    }

    public function testAnAlreadyNormalisedNumberIsUnchanged(): void
    {
        self::assertSame('+436641234567', $this->normalizer->normalize('+436641234567', 'AT'));
    }

    public function testAnEmptyValueStaysEmpty(): void
    {
        self::assertSame('', $this->normalizer->normalize('', 'AT'));
        self::assertSame('', $this->normalizer->normalize('   ', 'AT'));
    }

    public function testAnUnknownCountryLeavesTheInputAlone(): void
    {
        // Better an unchanged number than a wrong prefix. A missing "+" is
        // visible; a wrong country code is not — the number looks correct and
        // is unreachable.
        self::assertSame('0664 1234567', $this->normalizer->normalize('0664 1234567', 'XX'));
    }

    public function testANationalNumberWithoutACountryIsLeftAlone(): void
    {
        self::assertSame('0664 1234567', $this->normalizer->normalize('0664 1234567', ''));
    }

    public function testKeepsAnInternationalNumberFromAnotherCountry(): void
    {
        // Customer in Austria, phone in Switzerland — must not be rewritten.
        self::assertSame('+41791234567', $this->normalizer->normalize('+41 79 123 45 67', 'AT'));
    }
}
