<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderIsPublic;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;

/**
 * Michael, 2026-09-24: „wichtig für die DSGVO, wird ein Ordner ausgewählt,
 * sollte der gleich automatisch gesperrt werden".
 *
 * Gegen ein echtes Verzeichnis: Die Frage ist, welche Datei auf der Platte
 * liegt, und die beantwortet keine Attrappe.
 */
class ArchiveFolderProtectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/consho-archive-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->root.'/files/shop/rechnungen');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
    }

    public function testEinGeschuetzterOrdnerBleibtWieErIst(): void
    {
        self::assertFalse($this->protector()->protect('files/shop/rechnungen'));
        self::assertNull($this->protector()->publicFolderOf('files/shop/rechnungen'));
    }

    public function testEinOeffentlicherOrdnerWirdGesperrt(): void
    {
        touch($this->root.'/files/shop/rechnungen/.public');

        self::assertTrue($this->protector()->protect('files/shop/rechnungen'));
        self::assertFileDoesNotExist($this->root.'/files/shop/rechnungen/.public');
    }

    /**
     * 🔴 Ein öffentlicher OBERORDNER wird nicht angefasst: Ihn zu sperren
     * nähme alles andere darin von der Website. Die Auswahl wird abgelehnt,
     * der Oberordner genannt — und sein Marker bleibt liegen.
     */
    public function testEinOeffentlicherOberordnerWirdGenanntNichtGesperrt(): void
    {
        touch($this->root.'/files/shop/.public');

        try {
            $this->protector()->protect('files/shop/rechnungen');
            self::fail('Ein öffentlicher Oberordner hätte abgelehnt werden müssen.');
        } catch (ArchiveFolderIsPublic $e) {
            self::assertSame('files/shop', $e->publicFolder);
        }

        self::assertFileExists($this->root.'/files/shop/.public');
    }

    /**
     * Auch `files/` selbst zählt — ein Marker dort macht alles öffentlich.
     */
    public function testAuchDasWurzelverzeichnisZaehlt(): void
    {
        touch($this->root.'/files/.public');

        self::assertSame('files', $this->protector()->publicFolderOf('files/shop/rechnungen'));
    }

    private function protector(): ArchiveFolderProtector
    {
        return new ArchiveFolderProtector($this->root);
    }
}
