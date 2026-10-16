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
        // U+00A0 (geschütztes Leerzeichen) und U+202F (schmales geschütztes
        // Leerzeichen) landen beim Kopieren aus IBAN-Generatoren im Feld und
        // überstehen ein '/\s+/' ohne 'u'-Modifier unbeschadet.
        self::assertSame('AT611904300234573201', (new IbanListener())("AT61\u{00A0}1904\u{202F}3002 3457 3201"));
    }

    public function testFormatzeichenWerdenEbenfallsEntfernt(): void
    {
        // U+00AD (weicher Trennstrich), U+200B (Zero-Width-Space) und U+FEFF
        // (BOM) landen aus PDF-Kopien im Feld und sind unsichtbar — \p{Cf}
        // fängt sie, wo \s und \p{Z} allein sie durchlassen.
        self::assertSame('AT611904300234573201', (new IbanListener())("AT61\u{00AD}1904\u{200B}3002\u{FEFF}34573201"));
    }

    public function testUngueltigesUtf8WirdAbgelehnt(): void
    {
        // preg_replace() liefert hier null, nicht '' — das darf nicht als
        // leeres, optionales Feld durchgehen. StringUtil::specialchars()
        // ersetzt die ungültige Byte-Folge mit ENT_SUBSTITUTE durch U+FFFD.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("keine IBAN: AT61\u{FFFD}");

        (new IbanListener())("AT61\xff");
    }

    public function testDieFehlermeldungEscaptDenWertHtml(): void
    {
        // Widget::getErrorAsHTML() druckt die Meldung unescaped — Markup in
        // der Eingabe darf nicht ins Backend durchschlagen.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('keine IBAN: AT61&lt;B&gt;');

        (new IbanListener())('at61<b>');
    }

    public function testEineVertippteIbanWirdAbgelehnt(): void
    {
        // Eine Ziffer vertauscht: Die Prüfziffer fängt es, bevor ein Kunde auf
        // ein fremdes Konto überweist.
        $this->expectExceptionMessage('keine IBAN: AT611904300234573210');

        (new IbanListener())('AT61 1904 3002 3457 3210');
    }

    public function testLeerBleibtLeer(): void
    {
        // Pflicht ist das Feld über eval.mandatory; leer erreicht den Callback
        // nur, wenn ein Modul das ändert.
        self::assertSame('', (new IbanListener())(''));
    }
}
