<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\IbanListener;

class IbanListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors']['ibanInvalid'] = 'keine IBAN: %s';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDerCallbackIstGenauEinmalRegistriert(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(IbanListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                self::assertSame(IbanListener::class, $method->getDeclaringClass()->getName(), $method->getName());

                $arguments = $attribute->getArguments();
                $targets[] = $arguments['table'].' '.$arguments['target'].' '.$method->getName();
            }
        }

        self::assertSame(['tl_consho_payment_method fields.iban.save __invoke'], $targets);
    }

    public function testEineGueltigeIbanWirdOhneLeerzeichenInGrossbuchstabenGespeichert(): void
    {
        self::assertSame('AT611904300234573201', (new IbanListener())('at61 1904 3002 3457 3201'));
        self::assertSame('DE89370400440532013000', (new IbanListener())('DE89 3704 0044 0532 0130 00'));
    }

    public function testGeschuetzteLeerzeichenWerdenEbenfallsEntfernt(): void
    {
        // U+00A0 (no-break space) and U+202F (narrow no-break space) end up in
        // the field when copying from IBAN generators and survive a '/\s+/'
        // without the 'u' modifier unharmed.
        self::assertSame('AT611904300234573201', (new IbanListener())("AT61\u{00A0}1904\u{202F}3002 3457 3201"));
    }

    public function testFormatzeichenWerdenEbenfallsEntfernt(): void
    {
        // U+00AD (soft hyphen), U+200B (zero-width space) and U+FEFF (BOM)
        // end up in the field from PDF copies and are invisible — \p{Cf}
        // catches them where \s and \p{Z} alone let them through.
        self::assertSame('AT611904300234573201', (new IbanListener())("AT61\u{00AD}1904\u{200B}3002\u{FEFF}34573201"));
    }

    public function testUngueltigesUtf8WirdAbgelehnt(): void
    {
        // preg_replace() returns null here, not '' — that must not pass as an
        // empty, optional field. StringUtil::specialchars() replaces the
        // invalid byte sequence with U+FFFD via ENT_SUBSTITUTE.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("keine IBAN: AT61\u{FFFD}");

        (new IbanListener())("AT61\xff");
    }

    public function testDieFehlermeldungEscaptDenWertHtml(): void
    {
        // Widget::getErrorAsHTML() prints the message unescaped — markup in the
        // input must not get through to the backend.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('keine IBAN: AT61&lt;B&gt;');

        (new IbanListener())('at61<b>');
    }

    public function testEineVertippteIbanWirdAbgelehnt(): void
    {
        // One digit swapped: the check digit catches it before a customer
        // transfers money to somebody else's account.
        $this->expectExceptionMessage('keine IBAN: AT611904300234573210');

        (new IbanListener())('AT61 1904 3002 3457 3210');
    }

    public function testLeerBleibtLeer(): void
    {
        // The field is mandatory via eval.mandatory; empty only reaches the
        // callback if a module changes that.
        self::assertSame('', (new IbanListener())(''));
    }
}
