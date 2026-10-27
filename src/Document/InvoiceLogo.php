<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * The logo an invoice snapshot names — or none.
 *
 * The snapshot holds the file's UUID and hash, not the image. The logo is
 * printed only while the file under that UUID still has that content;
 * otherwise the invoice goes out WITHOUT a logo and the caller is told, so it
 * can say so. A missing logo on a regenerated invoice is a gap; a different
 * one would be a claim about the original that is not true.
 *
 * The hash is taken from the CONTENT, not from tl_files. Contao's DBAFS
 * computes the same MD5 (FilesystemConfiguration::addDefaultDbafs, 'md5'),
 * but by default it skips re-hashing while the modification time is
 * unchanged (`useLastModified`), and a file replaced by FTP is not in the
 * table until the next sync. The file is what gets printed, so the file is
 * what gets checked.
 *
 * Handed to the PDF engine as a data: URI: DompdfRenderer loads nothing.
 */
final class InvoiceLogo
{
    private const TYPES = [IMAGETYPE_PNG => 'image/png', IMAGETYPE_JPEG => 'image/jpeg'];

    public function __construct(
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
    ) {
    }

    /**
     * @param array{uuid?: string, hash?: string}|null $logo from the snapshot
     *
     * @return array{src: string|null, missing: bool} `missing` when the snapshot named a
     *                                                logo that cannot be printed
     */
    public function resolve(array|null $logo): array
    {
        $uuid = $logo['uuid'] ?? '';
        $hash = $logo['hash'] ?? '';

        if ('' === $uuid || '' === $hash) {
            return ['src' => null, 'missing' => false];
        }

        if (!Uuid::isValid($uuid)) {
            return ['src' => null, 'missing' => true];
        }

        try {
            $contents = $this->files->read(Uuid::fromString($uuid));
        } catch (UnableToResolveUuidException|VirtualFilesystemException) {
            // Two different exceptions, not one: a UUID no longer in
            // tl_files is a plain RuntimeException from the DbafsManager
            // (UnableToResolveUuidException), not a VirtualFilesystemException.
            // Catching only the latter let a deleted logo break the invoice.
            return ['src' => null, 'missing' => true];
        }

        if (!hash_equals($hash, md5($contents))) {
            return ['src' => null, 'missing' => true];
        }

        $size = @getimagesizefromstring($contents);
        $type = false === $size ? 0 : $size[2];

        if (!isset(self::TYPES[$type]) || self::isCmyk($contents)) {
            return ['src' => null, 'missing' => true];
        }

        return ['src' => 'data:'.self::TYPES[$type].';base64,'.base64_encode($contents), 'missing' => false];
    }

    /**
     * 🔴 A CMYK JPEG — the colour model of print, and so of many logos a
     * designer hands over — makes dompdf throw in PDF/A mode ("CMYK images
     * are not supported when generating a document in PDF/A mode",
     * Cpdf.php; measured in DompdfRendererTest). Printed as it is, it would
     * stop EVERY invoice of the shop, the archive included, until someone
     * replaced the logo. So it counts as unusable here, and the shop mask
     * refuses it on save (InvoiceLogoColourListener).
     *
     * getimagesize() reports four channels for CMYK (and YCCK) JPEGs. PNG has
     * no CMYK mode, so only JPEG can be affected.
     */
    public static function isCmyk(string $contents): bool
    {
        $size = @getimagesizefromstring($contents);

        return false !== $size && IMAGETYPE_JPEG === $size[2] && 4 === ($size['channels'] ?? 3);
    }
}
