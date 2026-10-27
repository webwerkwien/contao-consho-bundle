<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceLogo;

/**
 * Store the UUID; if the image is missing → a warning when the invoice is
 * issued again, and the logo is left out. Plus the checksum, because the
 * UUID stays attached to a replaced logo.
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
     * 🔴 The case the checksum exists for: the same UUID, different content.
     * It would print a logo the invoice never carried.
     */
    public function testEinAusgetauschtesLogoWirdWeggelassenUndGemeldet(): void
    {
        self::assertSame(
            ['src' => null, 'missing' => true],
            $this->logo(base64_decode(self::PNG, true))->resolve(['uuid' => self::UUID, 'hash' => md5('das alte Logo')]),
        );
    }

    /**
     * Deleted: Contao then throws NO VirtualFilesystemException but an
     * UnableToResolveUuidException — a plain RuntimeException.
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
     * If the checksum matches but it is not a PNG or JPEG, nothing is printed
     * either — dompdf would otherwise get something it does not render.
     */
    public function testKeinBildWirdNichtGedruckt(): void
    {
        self::assertSame(
            ['src' => null, 'missing' => true],
            $this->logo('<svg/>')->resolve(['uuid' => self::UUID, 'hash' => md5('<svg/>')]),
        );
    }

    /**
     * 🔴 A CMYK JPEG would make dompdf abort in PDF/A mode — and with it
     * EVERY invoice of the shop. It is left out and reported. An RGB JPEG next
     * to it gets through: the defence targets the colour model, not JPEG.
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
