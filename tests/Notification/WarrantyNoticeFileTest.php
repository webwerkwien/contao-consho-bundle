<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Notification;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\FilesystemItem;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Webwerkwien\ContaoConshoBundle\Notification\WarrantyNoticeFile;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

class WarrantyNoticeFileTest extends TestCase
{
    private const UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120002';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'consho_warrantyNotice' => Uuid::fromString(self::UUID)->toBinary()]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root']);
        $this->connection->insert('tl_page', ['id' => 3, 'type' => 'regular', 'consho_warrantyNotice' => Uuid::fromString(self::UUID)->toBinary()]);
    }

    public function testDieGewaehlteDateiWirdMitNameUndTypGeliefert(): void
    {
        $notice = $this->file('files/recht/mitteilung-de.pdf')->forRoot(1);

        self::assertSame(['name' => 'mitteilung-de.pdf', 'contents' => '%PDF-x', 'contentType' => 'application/pdf'], $notice);
    }

    public function testOhneAuswahlOderAufEinerNormalenSeiteNichts(): void
    {
        self::assertNull($this->file('files/a.pdf')->forRoot(2));
        self::assertNull($this->file('files/a.pdf')->forRoot(3));
    }

    public function testEinFremderDateitypWirdNichtVerschickt(): void
    {
        self::assertNull($this->file('files/a.svg')->forRoot(1));
    }

    public function testEineGeloeschteDateiIstKeineMitteilung(): void
    {
        $files = $this->createStub(VirtualFilesystemInterface::class);
        $files->method('get')->willThrowException(new UnableToResolveUuidException(Uuid::fromString(self::UUID)));

        self::assertNull((new WarrantyNoticeFile($this->connection, $files))->forRoot(1));
    }

    private function file(string $path): WarrantyNoticeFile
    {
        $files = $this->createStub(VirtualFilesystemInterface::class);
        $files->method('get')->willReturn(new FilesystemItem(true, $path));
        $files->method('read')->willReturn('%PDF-x');

        return new WarrantyNoticeFile($this->connection, $files);
    }
}
