<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Document;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderIsPublic;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;

/**
 * Important for the GDPR: when a folder is selected, it should be protected
 * automatically right away.
 *
 * Against a real directory: the question is which file lies on the disk,
 * and no test double answers that.
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
     * 🔴 A public PARENT folder is not touched: protecting it would take
     * everything else in it off the website. The selection is rejected, the
     * parent folder named — and its marker stays where it is.
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
     * `files/` itself counts too — a marker there makes everything public.
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
