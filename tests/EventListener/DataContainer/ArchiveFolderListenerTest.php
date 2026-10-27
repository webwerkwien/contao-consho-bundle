<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\Automator;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ArchiveFolderListener;

class ArchiveFolderListenerTest extends TestCase
{
    private const UUID = 'b0b4c1e2-9a7b-11ef-8d4f-0242ac120099';

    private string $root;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/consho-archive-listener-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->root.'/files/rechnungen');

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_files (id INTEGER PRIMARY KEY, uuid BLOB NULL, type TEXT NOT NULL DEFAULT '', path TEXT NOT NULL DEFAULT '')");
        $this->connection->insert('tl_files', ['uuid' => StringUtil::uuidToBin(self::UUID), 'type' => 'folder', 'path' => 'files/rechnungen']);

        $GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderParentPublic'] = 'öffentlich: %1$s';
        $GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderProtected'] = 'gesperrt: %s';
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->root);
        unset($GLOBALS['TL_LANG']);
    }

    /**
     * Like the file manager: marker gone, symlinks anew, log. Without the
     * symlinks the old link under public/ would keep serving the folder.
     */
    public function testEinOeffentlicherOrdnerWirdBeimSpeichernGesperrtWieImDateimanager(): void
    {
        touch($this->root.'/files/rechnungen/.public');

        $automator = $this->createMock(Automator::class);
        $automator->expects(self::once())->method('generateSymlinks');

        $message = $this->createMock(Adapter::class);
        $message->expects(self::once())->method('__call')->with('addConfirmation', ['gesperrt: files/rechnungen']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');

        $value = StringUtil::uuidToBin(self::UUID);

        self::assertSame($value, $this->listener($automator, $message, $logger)($value));
        self::assertFileDoesNotExist($this->root.'/files/rechnungen/.public');
    }

    public function testEinGeschuetzterOrdnerLoestNichtsAus(): void
    {
        $automator = $this->createMock(Automator::class);
        $automator->expects(self::never())->method('generateSymlinks');

        $this->listener($automator)(StringUtil::uuidToBin(self::UUID));
    }

    /**
     * The exception appears at the field and holds the form — the selection is
     * never saved.
     */
    public function testEinOeffentlicherOberordnerWirdAmFeldAbgelehnt(): void
    {
        touch($this->root.'/files/.public');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('öffentlich: files');

        $this->listener($this->createMock(Automator::class))(StringUtil::uuidToBin(self::UUID));
    }

    /**
     * @param Adapter<Message>|null $message
     */
    private function listener(Automator $automator, Adapter|null $message = null, LoggerInterface|null $logger = null): ArchiveFolderListener
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('createInstance')->with(Automator::class)->willReturn($automator);
        $framework->method('getAdapter')->with(Message::class)->willReturn($message ?? $this->createMock(Adapter::class));

        return new ArchiveFolderListener(
            $this->connection,
            new ArchiveFolderProtector($this->root),
            $framework,
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }
}
