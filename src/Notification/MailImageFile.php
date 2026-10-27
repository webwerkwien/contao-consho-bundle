<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * An image from the file manager, ready to be embedded in a mail (block
 * GARAN, GA4 — and the shop logo after it).
 *
 * Embedded, not linked: a mail programme blocks remote images until the
 * reader allows them, and a linked image is not part of the mail — the
 * confirmation on a durable medium (§ 7 Abs. 3 FAGG) would not contain it.
 *
 * PNG and JPG only: Gmail and Outlook strip SVG, and anything else was not
 * chosen through a field that allows it.
 */
class MailImageFile
{
    private const TYPES = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
    ];

    public function __construct(
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
    ) {
    }

    /**
     * @param string $binary the file's UUID as stored (16 bytes)
     *
     * @return array{name: string, contents: string, contentType: string}|null null when the file is gone or not an image of these types
     */
    public function read(string $binary): array|null
    {
        if (16 !== \strlen($binary)) {
            return null;
        }

        $uuid = Uuid::fromBinary($binary);

        try {
            $item = $this->files->get($uuid);

            if (null === $item || !$item->isFile()) {
                return null;
            }

            $type = self::TYPES[$item->getExtension(true)] ?? null;

            return null === $type ? null : ['name' => $item->getName(), 'contents' => $this->files->read($uuid), 'contentType' => $type];
        } catch (UnableToResolveUuidException|VirtualFilesystemException) {
            return null;
        }
    }
}
