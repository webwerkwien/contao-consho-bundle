<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\Automator;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderIsPublic;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;

/**
 * Protects the invoice archive folder the moment it is chosen (for the
 * GDPR) — see ArchiveFolderProtector for why a public
 * parent is refused rather than protected.
 *
 * Done the way the file manager does it (tl_files, the `protected` field):
 * remove the marker, regenerate the symlinks, log to contao.files. The
 * symlinks are not optional — the folder's old symlink under public/ would
 * otherwise go on serving it.
 *
 * A save callback, not onsubmit: an exception here is shown at the field and
 * keeps the form, so a refused folder is never stored.
 */
class ArchiveFolderListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ArchiveFolderProtector $protector,
        private readonly ContaoFramework $framework,
        #[Autowire(service: 'monolog.logger.contao.files')]
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'fields.invoiceArchiveFolder.save')]
    public function __invoke(mixed $value): mixed
    {
        if (!\is_string($value) || '' === $value) {
            return $value;
        }

        $path = $this->connection->fetchOne("SELECT path FROM tl_files WHERE uuid = ? AND type = 'folder'", [$value]);

        if (!\is_string($path) || '' === $path) {
            return $value;
        }

        try {
            $protected = $this->protector->protect($path);
        } catch (ArchiveFolderIsPublic $e) {
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderParentPublic'] ?? '%s', $e->publicFolder), 0, $e);
        }

        if ($protected) {
            $this->framework->createInstance(Automator::class)->generateSymlinks();
            $this->logger->info(\sprintf('Folder "%s" has been protected (invoice archive)', $path));

            $this->framework->getAdapter(Message::class)->addConfirmation(\sprintf($GLOBALS['TL_LANG']['tl_consho_shop']['archiveFolderProtected'] ?? '%s', $path));
        }

        return $value;
    }
}
