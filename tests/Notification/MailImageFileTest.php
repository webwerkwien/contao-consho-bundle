<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\FilesystemItem;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webwerkwien\ContaoConshoBundle\Notification\MailImageFile;

/**
 * Block GARAN (GA4): ein Bild zum Einbetten — PNG und JPG, sonst nichts.
 */
class MailImageFileTest extends TestCase
{
    private const UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120002';

    public function testPngUndJpgMitNameUndTyp(): void
    {
        self::assertSame(['name' => 'garan.png', 'contents' => 'BYTES', 'contentType' => 'image/png'], $this->file('files/garan.png')->read($this->binary()));
        self::assertSame('image/jpeg', $this->file('files/garan.JPG')->read($this->binary())['contentType'] ?? null);
    }

    public function testSvgUndAnderesNicht(): void
    {
        // Gmail und Outlook entfernen SVG aus Mails.
        self::assertNull($this->file('files/garan.svg')->read($this->binary()));
        self::assertNull($this->file('files/garan.pdf')->read($this->binary()));
    }

    public function testKeineGueltigeUuidOderGeloeschteDatei(): void
    {
        self::assertNull($this->file('files/garan.png')->read(''));

        $files = $this->createStub(VirtualFilesystemInterface::class);
        $files->method('get')->willThrowException(new UnableToResolveUuidException(Uuid::fromString(self::UUID)));

        self::assertNull((new MailImageFile($files))->read($this->binary()));
    }

    private function binary(): string
    {
        return Uuid::fromString(self::UUID)->toBinary();
    }

    private function file(string $path): MailImageFile
    {
        $files = $this->createStub(VirtualFilesystemInterface::class);
        $files->method('get')->willReturn(new FilesystemItem(true, $path));
        $files->method('read')->willReturn('BYTES');

        return new MailImageFile($files);
    }
}
