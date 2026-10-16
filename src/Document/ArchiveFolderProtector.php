<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;

/**
 * Keeps the invoice archive folder protected (Michael, 2026-09-24: "wird ein
 * Ordner ausgewählt, sollte der gleich automatisch gesperrt werden").
 *
 * An invoice carries a name, an address and what someone bought. In a public
 * folder every PDF is a download for anyone who guesses the file name — and
 * invoice numbers are sequential, so guessing is counting.
 *
 * In Contao 5 a folder is public while it or ANY parent holds a `.public`
 * file (Dbafs::FILE_MARKER_PUBLIC, Folder::isUnprotected()). Two cases:
 *
 *   - The chosen folder itself is marked: the marker goes, exactly as
 *     Folder::protect() does it. The caller then regenerates the symlinks,
 *     as tl_files does after protect() — without that, the old symlink under
 *     public/ keeps serving the folder although it is "protected".
 *   - A PARENT is marked: nothing is changed. Protecting the parent would
 *     take everything else in it off the website — images, downloads — and
 *     that is not a side effect an invoice setting may have. Contao itself
 *     refuses here too ("one of its parent folders is public"). The choice is
 *     rejected and the parent named.
 */
final class ArchiveFolderProtector
{
    private const MARKER = '.public';

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * The first folder, from the chosen one upwards, that makes it public —
     * or null when it is protected.
     */
    public function publicFolderOf(string $path): string|null
    {
        $path = Path::canonicalize($path);

        while ('' !== $path && '.' !== $path) {
            if (is_file($this->projectDir.'/'.$path.'/'.self::MARKER)) {
                return $path;
            }

            $parent = Path::getDirectory($path);

            if ($parent === $path) {
                break;
            }

            $path = $parent;
        }

        return null;
    }

    /**
     * @return bool whether a marker was removed (and the symlinks need regenerating)
     *
     * @throws ArchiveFolderIsPublic when a parent folder makes it public
     */
    public function protect(string $path): bool
    {
        $public = $this->publicFolderOf($path);

        if (null === $public) {
            return false;
        }

        if ($public !== Path::canonicalize($path)) {
            throw ArchiveFolderIsPublic::becauseOf($public);
        }

        // Same guard as Folder::protect() (contao/contao#3465).
        if (!is_file($this->projectDir.'/'.$public.'/'.self::MARKER)) {
            throw ArchiveFolderIsPublic::becauseOf($public);
        }

        unlink($this->projectDir.'/'.$public.'/'.self::MARKER);

        return true;
    }
}
