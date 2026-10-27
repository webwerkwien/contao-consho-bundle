<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog\Read;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\StringUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;

/**
 * Product pages through ProductUrlGenerator — the one place Consho builds
 * them — and images through Contao's file system.
 *
 * ⚠️ Without a request Contao's public URI of a local file has no host
 * (SymlinkedLocalFilesProvider takes it from the current request and answers
 * "" otherwise). A relative answer gets the start point's base in front; a
 * configured static or remote URL comes back absolute and stays as it is.
 *
 * Only PUBLIC files: a protected folder answers 403, and a feed listing an
 * image nobody may load gets the item rejected (PublicFiles, as for the
 * logo in mails).
 */
final class CatalogAddresses implements CatalogAddressesInterface
{
    public function __construct(
        private readonly ProductUrlGenerator $urls,
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
        private readonly PublicFiles $public,
    ) {
    }

    public function product(int $mainId, int $variantId, CatalogContext $context): string
    {
        try {
            return $this->urls->generate($mainId, $context->rootPageId, UrlGeneratorInterface::ABSOLUTE_URL, $variantId);
        } catch (ProductPageMissing) {
            return '';
        }
    }

    public function images(array $uuids, CatalogContext $context): array
    {
        $addresses = [];

        foreach ($uuids as $uuid) {
            if (!$this->public->isPublic(StringUtil::uuidToBin($uuid))) {
                continue;
            }

            /*
             * As a Uuid, never as the string: generatePublicUri() takes a
             * string for a PATH, and answered "files/<uuid>" — an address of a
             * file that does not exist (the test double had taken any
             * string).
             */
            $uri = (string) $this->files->generatePublicUri(Uuid::fromString($uuid));

            if ('' === $uri) {
                continue;
            }

            $addresses[] = 1 === preg_match('~^https?://~i', $uri) ? $uri : $context->baseUrl.'/'.ltrim($uri, '/');
        }

        return $addresses;
    }
}
