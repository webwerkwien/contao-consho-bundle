<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;

/**
 * Not a unit test — it reads the source instead of calling anything (B-8, E2).
 *
 * A started session makes a response private: the shared page cache no longer
 * stores it, for anybody. The filter state lives in the address since A-10 for
 * exactly this reason (Block B, 9.2). The mistake that
 * breaks it is putting "just the last sort order" into the session for
 * convenience — nothing fails, the shop just gets slower.
 *
 * A functional test would need a Contao kernel, which the bundle does not
 * have. So this test forbids the access itself in every file that renders or
 * serves a catalogue page; the response headers themselves only show on a
 * running installation.
 */
class CatalogStaysSessionFreeTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const FRONT_END = [
        'src/Catalog',
        'src/Seo',
        'src/Controller/ContentElement',
        'src/Controller/Page',
        'src/EventListener/SitemapListener.php',
        'src/EventListener/SitemapCacheLifetimeListener.php',
        'src/EventListener/RobotsTxtListener.php',
    ];

    /**
     * @var list<string>
     */
    private const SESSION_ACCESS = [
        '/->getSession\s*\(/',
        '/->hasSession\s*\(/',
        '/\bSessionInterface\b/',
        '/\$_SESSION\b/',
        '/session_start\s*\(/',
        '/\bFlashBag/',
    ];

    public function testNoCatalogueCodeTouchesTheSession(): void
    {
        $offenders = [];

        foreach ($this->files() as $relative => $contents) {
            foreach (self::SESSION_ACCESS as $pattern) {
                if (1 === preg_match($pattern, $contents)) {
                    $offenders[] = $relative;

                    break;
                }
            }
        }

        self::assertSame([], $offenders, 'A session in catalogue code makes every catalogue page private to the shared cache.');
    }

    public function testItActuallyReadsTheFiles(): void
    {
        // A test that finds no file would always be green.
        self::assertGreaterThan(20, \count($this->files()));
        self::assertArrayHasKey('src/Controller/ContentElement/ProductListController.php', $this->files());
    }

    /**
     * @return array<string, string>
     */
    private function files(): array
    {
        $root = \dirname(__DIR__, 2);
        $files = [];

        foreach (self::FRONT_END as $path) {
            $absolute = $root.'/'.$path;

            if (is_file($absolute)) {
                $files[$path] = (string) file_get_contents($absolute);

                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));
                    $files[$relative] = (string) file_get_contents($file->getPathname());
                }
            }
        }

        ksort($files);

        return $files;
    }
}
