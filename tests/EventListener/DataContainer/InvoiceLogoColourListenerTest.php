<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\InvoiceLogoColourListener;

/**
 * A CMYK logo is rejected on save already — at the field, with the reason,
 * instead of later as an invoice without a logo (C-8).
 */
class InvoiceLogoColourListenerTest extends TestCase
{
    private const UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120002';

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testEinCmykLogoWirdAmFeldAbgelehnt(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLogoCmyk'] = 'bitte als RGB';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bitte als RGB');

        $this->listener('logo-cmyk.jpg')(StringUtil::uuidToBin(self::UUID));
    }

    public function testEinRgbLogoWirdGespeichert(): void
    {
        $value = StringUtil::uuidToBin(self::UUID);

        self::assertSame($value, $this->listener('logo-rgb.jpg')($value));
    }

    public function testKeinLogoIstKeinFall(): void
    {
        self::assertNull($this->listener('logo-rgb.jpg', expectRead: false)(null));
        self::assertSame('', $this->listener('logo-rgb.jpg', expectRead: false)(''));
    }

    private function listener(string $fixture, bool $expectRead = true): InvoiceLogoColourListener
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);
        $files->expects($expectRead ? self::once() : self::never())->method('read')
            ->willReturn((string) file_get_contents(__DIR__.'/../../Document/Fixtures/'.$fixture));

        return new InvoiceLogoColourListener($files);
    }
}
