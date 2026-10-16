<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceLogo;

/**
 * Michael, 2026-09-24: UUID speichern, fehlt die Grafik → Warnung bei der
 * erneuten Ausstellung und weglassen. Dazu die Prüfsumme, weil die UUID an
 * einem ausgetauschten Logo hängen bleibt.
 */
class InvoiceLogoTest extends TestCase
{
    private const UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120002';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function testOhneLogoImSchnappschussWirdNichtsGemeldet(): void
    {
        self::assertSame(['src' => null, 'missing' => false], $this->logo('never')->resolve(null));
    }

    public function testDasUnveraenderteLogoWirdAlsDataAdresseGedruckt(): void
    {
        $png = base64_decode(self::PNG, true);

        self::assertSame(
            ['src' => 'data:image/png;base64,'.self::PNG, 'missing' => false],
            $this->logo($png)->resolve(['uuid' => self::UUID, 'hash' => md5((string) $png)]),
        );
    }

    /**
     * 🔴 Der Fall, für den die Prüfsumme da ist: dieselbe UUID, anderer
     * Inhalt. Gedruckt würde ein Logo, das die Rechnung nie trug.
     */
    public function testEinAusgetauschtesLogoWirdWeggelassenUndGemeldet(): void
    {
        self::assertSame(
            ['src' => null, 'missing' => true],
            $this->logo(base64_decode(self::PNG, true))->resolve(['uuid' => self::UUID, 'hash' => md5('das alte Logo')]),
        );
    }

    /**
     * Gelöscht: Contao wirft dann KEINE VirtualFilesystemException, sondern
     * UnableToResolveUuidException — ein schlichtes RuntimeException.
     */
    public function testEinGeloeschtesLogoWirdWeggelassenUndGemeldet(): void
    {
        self::assertSame(
            ['src' => null, 'missing' => true],
            $this->logo(new UnableToResolveUuidException(Uuid::fromString(self::UUID)))->resolve(['uuid' => self::UUID, 'hash' => 'x']),
        );
    }

    public function testEineUnlesbareDateiWirdWeggelassenUndGemeldet(): void
    {
        self::assertSame(
            ['src' => null, 'missing' => true],
            $this->logo(VirtualFilesystemException::unableToRead('logo.png'))->resolve(['uuid' => self::UUID, 'hash' => 'x']),
        );
    }

    /**
     * Stimmt die Prüfsumme, ist es aber kein PNG oder JPEG, wird trotzdem
     * nichts gedruckt — dompdf bekäme sonst etwas, das es nicht darstellt.
     */
    public function testKeinBildWirdNichtGedruckt(): void
    {
        self::assertSame(
            ['src' => null, 'missing' => true],
            $this->logo('<svg/>')->resolve(['uuid' => self::UUID, 'hash' => md5('<svg/>')]),
        );
    }

    /**
     * 🔴 Ein CMYK-JPEG ließe dompdf im PDF/A-Modus abbrechen — und damit
     * JEDE Rechnung des Shops. Es wird weggelassen und gemeldet. Ein RGB-JPEG
     * daneben kommt durch: Die Abwehr trifft das Farbmodell, nicht JPEG.
     */
    public function testEinCmykLogoWirdWeggelassenEinRgbJpegNicht(): void
    {
        $cmyk = (string) file_get_contents(__DIR__.'/Fixtures/logo-cmyk.jpg');
        $rgb = (string) file_get_contents(__DIR__.'/Fixtures/logo-rgb.jpg');

        self::assertSame(['src' => null, 'missing' => true], $this->logo($cmyk)->resolve(['uuid' => self::UUID, 'hash' => md5($cmyk)]));
        self::assertStringStartsWith('data:image/jpeg;base64,', (string) $this->logo($rgb)->resolve(['uuid' => self::UUID, 'hash' => md5($rgb)])['src']);
    }

    private function logo(string|\Throwable $contents): InvoiceLogo
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);

        if ('never' === $contents) {
            $files->expects(self::never())->method('read');
        } elseif ($contents instanceof \Throwable) {
            $files->method('read')->willThrowException($contents);
        } else {
            $files->method('read')->willReturn($contents);
        }

        return new InvoiceLogo($files);
    }
}
