<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Reads `tl_page.consho_warrantyNotice` of a start point into an attachment.
 *
 * The content type follows the extension, and only the three the field
 * allows: anything else was not chosen through the field and is not sent.
 */
final class WarrantyNoticeFile implements WarrantyNoticeSourceInterface
{
    private const TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
    ) {
    }

    public function forRoot(int $rootId): array|null
    {
        $binary = $this->connection->fetchOne("SELECT consho_warrantyNotice FROM tl_page WHERE id = ? AND type IN ('root', 'rootfallback')", [$rootId]);

        if (!\is_string($binary) || 16 !== \strlen($binary)) {
            return null;
        }

        $uuid = Uuid::fromBinary($binary);

        try {
            $item = $this->files->get($uuid);

            if (null === $item || !$item->isFile()) {
                return null;
            }

            $type = self::TYPES[$item->getExtension(true)] ?? null;

            if (null === $type) {
                return null;
            }

            // Two exceptions, as in InvoiceLogo: a UUID gone from tl_files is
            // a plain RuntimeException from the DbafsManager.
            return ['name' => $item->getName(), 'contents' => $this->files->read($uuid), 'contentType' => $type];
        } catch (UnableToResolveUuidException|VirtualFilesystemException) {
            return null;
        }
    }
}
