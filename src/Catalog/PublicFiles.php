<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * What the shop needs to know about an image file of the file manager before
 * it shows it (block GARAN).
 *
 * ── Public ───────────────────────────────────────────────────────────────
 *
 * Contao publishes a folder only when it or a folder above it carries a
 * `.public` marker (SymlinksCommand: one symlink under public/files per
 * marked folder). A file elsewhere answers 404 in the shop, and nothing in
 * the back end says so — the image component prints its address all the
 * same. A GARAN label in a new folder `files/consho`
 * stood on the product page as a broken image, while the confirmation mail,
 * which reads the file on the server, carried it.
 *
 * Asked past the DBAFS: `.public` is never synchronised into tl_files
 * (Contao\Dbafs skips it), so a check through the database would always
 * say "protected".
 *
 * ── Size ─────────────────────────────────────────────────────────────────
 *
 * An image embedded in a mail travels in every one of them, base64-encoded
 * at about 4/3 of its size; a large one makes mails heavy and spam filters
 * suspicious.
 */
class PublicFiles
{
    /** What a mail image may weigh before a finding says so (L4). */
    public const MAIL_IMAGE_MAX_BYTES = 100 * 1024;

    public function __construct(
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
    ) {
    }

    /**
     * @param string $binary the file's UUID as stored (16 bytes)
     */
    public function isPublic(string $binary): bool
    {
        $path = $this->pathOf($binary);

        if (null === $path) {
            return false;
        }

        $folder = \dirname($path);

        try {
            // Up to and including the root of the file manager.
            while (true) {
                $root = '.' === $folder || '' === $folder;

                if ($this->files->fileExists($root ? '.public' : $folder.'/.public', VirtualFilesystemInterface::BYPASS_DBAFS)) {
                    return true;
                }

                if ($root) {
                    return false;
                }

                $folder = \dirname($folder);
            }
        } catch (VirtualFilesystemException) {
            return false;
        }
    }

    /**
     * @param string $binary the file's UUID as stored (16 bytes)
     *
     * @return int|null bytes; null when the file is gone
     */
    public function size(string $binary): int|null
    {
        if (null === $this->pathOf($binary)) {
            return null;
        }

        try {
            return $this->files->getFileSize(Uuid::fromBinary($binary));
        } catch (UnableToResolveUuidException|VirtualFilesystemException) {
            return null;
        }
    }

    private function pathOf(string $binary): string|null
    {
        if (16 !== \strlen($binary)) {
            return null;
        }

        try {
            $item = $this->files->get(Uuid::fromBinary($binary));

            return null === $item || !$item->isFile() ? null : $item->getPath();
        } catch (UnableToResolveUuidException|VirtualFilesystemException) {
            return null;
        }
    }
}
