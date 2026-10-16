<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;
use Symfony\Component\Filesystem\Path;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlers;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;

/**
 * The delivery types a product can choose (F-4, F8): whatever is registered
 * — the core's file and key pool, and every module's. Labels from
 * tl_consho_product.digitalDeliveries, the key itself where a module brings
 * none.
 */
class DigitalDeliveryListener
{
    public function __construct(
        private readonly DeliveryHandlers $handlers,
        private readonly Connection $connection,
        private readonly ArchiveFolderProtector $folders,
    ) {
    }

    /**
     * A file in a public folder is refused (F-5, F18): anyone guessing the
     * address could fetch it, and the entitlement would decide nothing. The
     * folder is not protected on the spot as the invoice archive is — that
     * would take every other file in it off the website.
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.digitalFile.save')]
    public function refusePublicFile(mixed $value): mixed
    {
        if (!\is_string($value) || 16 !== \strlen($value)) {
            return $value;
        }

        $path = $this->connection->fetchOne('SELECT path FROM tl_files WHERE uuid = ?', [$value]);
        $public = false === $path ? null : $this->folders->publicFolderOf(Path::getDirectory((string) $path));

        if (null !== $public) {
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['tl_consho_product']['digitalFilePublic'] ?? 'Public folder: %s', $public));
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.digitalDelivery.options')]
    public function options(): array
    {
        $options = [];

        foreach ($this->handlers->keys() as $key) {
            $options[$key] = (string) ($GLOBALS['TL_LANG']['tl_consho_product']['digitalDeliveries'][$key] ?? $key);
        }

        return $options;
    }
}
