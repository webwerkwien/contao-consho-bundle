<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Path;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;

/**
 * The file of a digital article (F-5, F18) — and whether it may be handed out.
 *
 * Read at the download, not frozen at the release: an updated file is what
 * the holder of an active entitlement gets, which is the whole point of a
 * term (F1). A variant's own file where it has one, otherwise its main
 * product's.
 *
 * Only from a PROTECTED folder. In a public one every file is a download for
 * anyone who guesses its address, and "who may have it" would be decided by
 * the web server, not by the entitlement. The check runs here as well as on
 * saving the product, because a folder can be made public afterwards.
 */
class DigitalFiles
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ArchiveFolderProtector $folders,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{path: string, absolute: string, name: string}|null null without a file that exists in a protected folder
     */
    public function of(int $articleId): array|null
    {
        $path = $this->pathOf($articleId);

        if (null === $path || null !== $this->folders->publicFolderOf(Path::getDirectory($path))) {
            return null;
        }

        $absolute = Path::join($this->projectDir, $path);

        return is_file($absolute) ? ['path' => $path, 'absolute' => $absolute, 'name' => basename($path)] : null;
    }

    /**
     * The path in tl_files of the article's file, whatever state it is in.
     */
    public function pathOf(int $articleId): string|null
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.digitalFile, p.digitalFile AS mainFile
               FROM tl_consho_product a
               LEFT JOIN tl_consho_product p ON p.id = a.pid AND a.pid > 0
              WHERE a.id = ?',
            [$articleId],
        );

        if (false === $row) {
            return null;
        }

        $uuid = self::uuidOf($row['digitalFile'] ?? null) ?? self::uuidOf($row['mainFile'] ?? null);

        if (null === $uuid) {
            return null;
        }

        $path = $this->connection->fetchOne("SELECT path FROM tl_files WHERE uuid = ? AND type = 'file'", [$uuid]);

        return false === $path || '' === (string) $path ? null : (string) $path;
    }

    private static function uuidOf(mixed $value): string|null
    {
        return \is_string($value) && 16 === \strlen($value) ? $value : null;
    }
}
