<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog\Read;

use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\StringUtil;
use Nyholm\Psr7\Uri;
use Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogAddresses;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContext;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;

/**
 * Connect-1, CN-2: image addresses without a request — Contao answers a
 * local file with a path and no host there.
 */
class CatalogAddressesTest extends TestCase
{
    private const PUBLIC_RELATIVE = '00000000-0000-4000-8000-000000000001';
    private const PUBLIC_ABSOLUTE = '00000000-0000-4000-8000-000000000002';
    private const PROTECTED = '00000000-0000-4000-8000-000000000003';
    private const GONE = '00000000-0000-4000-8000-000000000004';

    public function testOeffentlicheBilderWerdenAbsolutGeschuetzteFallenWeg(): void
    {
        $files = $this->createMock(VirtualFilesystemInterface::class);
        /*
         * Like the real one: a STRING is a path, only a Uuid finds a file by
         * its UUID. The first double took any string and let "files/<uuid>"
         * through to the test server.
         */
        $files->method('generatePublicUri')->willReturnCallback(static fn (Uuid|string $location): Uri|null => !$location instanceof Uuid ? null : match ($location->toRfc4122()) {
            // What SymlinkedLocalFilesProvider gives without a request: "/files/…".
            self::PUBLIC_RELATIVE => new Uri('/files/shop/a.jpg'),
            self::PUBLIC_ABSOLUTE => new Uri('https://cdn.example/b.jpg'),
            default => null,
        });

        $public = $this->createMock(PublicFiles::class);
        $public->method('isPublic')->willReturnCallback(static fn (string $binary): bool => StringUtil::uuidToBin(self::PROTECTED) !== $binary);

        $urls = (new \ReflectionClass(ProductUrlGenerator::class))->newInstanceWithoutConstructor();
        $addresses = new CatalogAddresses($urls, $files, $public);

        self::assertSame(
            ['https://shop.example/files/shop/a.jpg', 'https://cdn.example/b.jpg'],
            $addresses->images([self::PUBLIC_RELATIVE, self::PROTECTED, self::PUBLIC_ABSOLUTE, self::GONE], $this->context()),
        );
    }

    private function context(): CatalogContext
    {
        return new CatalogContext(1, 1, 'de', 'de', 'EUR', 'AT', 'AT', false, [], 'https://shop.example');
    }
}
