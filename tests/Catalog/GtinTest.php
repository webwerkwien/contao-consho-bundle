<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\Gtin;

class GtinTest extends TestCase
{
    public function testAcceptsAValidEan13(): void
    {
        self::assertTrue(Gtin::isValid('4006381333931'));
    }

    public function testRejectsAWrongCheckDigit(): void
    {
        // 🎯 The test this class exists for. A length check alone lets every
        // mistyped digit through, and a mistyped GTIN identifies SOMEBODY
        // ELSE'S product — in the price comparison, in the JSON-LD, on the
        // delivery note. Wrong is worse than absent here.
        self::assertFalse(Gtin::isValid('4006381333930'));
    }

    public function testAcceptsEveryLengthTheStandardKnows(): void
    {
        self::assertTrue(Gtin::isValid('12345670'), 'GTIN-8');
        self::assertTrue(Gtin::isValid('036000291452'), 'GTIN-12');
        self::assertTrue(Gtin::isValid('9780201379624'), 'GTIN-13');
        self::assertTrue(Gtin::isValid('01234567890128'), 'GTIN-14');
    }

    public function testRejectsALengthTheStandardDoesNotKnow(): void
    {
        // Ten digits is not a GTIN, however well the check digit adds up.
        self::assertFalse(Gtin::isValid('1234567895'));
        self::assertFalse(Gtin::isValid('123456789012345'));
    }

    public function testStripsWhatPeopleTypeBetweenTheDigits(): void
    {
        // Printed EANs are grouped, and an operator copies what they see.
        // Refusing "4 006381 333931" would be pedantry, not a check.
        self::assertSame('4006381333931', Gtin::normalise('4 006381 333931'));
        self::assertSame('4006381333931', Gtin::normalise('40063813-3393 1'));
    }

    public function testAnEmptyValueIsNoGtinAndNoError(): void
    {
        // Not every article has one. Absent is a legitimate state; only a
        // WRONG one is a problem.
        self::assertSame('', Gtin::normalise('   '));
        self::assertTrue(Gtin::isValid(''));
    }

    public function testRejectsLetters(): void
    {
        self::assertFalse(Gtin::isValid('abcdefghijklm'));
        self::assertFalse(Gtin::isValid('400638133393X'));
    }

    public function testNamesTheKindItIsSoBlockBCanEmitTheRightProperty(): void
    {
        self::assertSame('gtin8', Gtin::kind('12345670'));
        self::assertSame('gtin12', Gtin::kind('036000291452'));
        self::assertSame('gtin13', Gtin::kind('9780201379624'));
        self::assertSame('gtin14', Gtin::kind('01234567890128'));
        self::assertNull(Gtin::kind(''));
    }
}
