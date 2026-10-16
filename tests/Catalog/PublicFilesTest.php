<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\FilesystemItem;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;

/**
 * Abnahme GARAN auf c5: öffentlich ist ein Ordner mit `.public` — er selbst
 * oder einer darüber (SymlinksCommand), gefragt an der DBAFS vorbei.
 */
class PublicFilesTest extends TestCase
{
    private const UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120002';

    public function testDerOrdnerOderEinElternordnerTraegtDenMarker(): void
    {
        self::assertTrue($this->files('consho/garan/label.png', ['consho/garan/.public'])->isPublic($this->binary()));
        self::assertTrue($this->files('consho/garan/label.png', ['consho/.public'])->isPublic($this->binary()));
        self::assertTrue($this->files('label.png', ['.public'])->isPublic($this->binary()), 'eine Datei direkt in files/');
    }

    public function testOhneMarkerGeschuetzt(): void
    {
        self::assertFalse($this->files('consho/garan/label.png', ['andere/.public'])->isPublic($this->binary()));
        self::assertFalse($this->files('consho/label.png', [])->isPublic(''));
    }

    public function testEineGeloeschteDateiIstWederOeffentlichNochHatSieEineGroesse(): void
    {
        $files = $this->createStub(VirtualFilesystemInterface::class);
        $files->method('get')->willThrowException(new UnableToResolveUuidException(Uuid::fromString(self::UUID)));

        self::assertFalse((new PublicFiles($files))->isPublic($this->binary()));
        self::assertNull((new PublicFiles($files))->size($this->binary()));
    }

    public function testDieGroesse(): void
    {
        self::assertSame(2048, $this->files('consho/label.png', [])->size($this->binary()));
    }

    /**
     * @param list<string> $markers
     */
    private function files(string $path, array $markers): PublicFiles
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);
        $files->method('get')->willReturn(new FilesystemItem(true, $path));
        $files->method('getFileSize')->willReturn(2048);
        $files->method('fileExists')->willReturnCallback(static function (string $location, int $flags) use ($markers): bool {
            // Die DBAFS kennt .public nie — ohne BYPASS_DBAFS wäre jede Antwort falsch.
            self::assertSame(VirtualFilesystemInterface::BYPASS_DBAFS, $flags);

            return \in_array($location, $markers, true);
        });

        return new PublicFiles($files);
    }

    private function binary(): string
    {
        return Uuid::fromString(self::UUID)->toBinary();
    }
}
