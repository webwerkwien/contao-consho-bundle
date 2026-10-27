<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Seo;

use PHPUnit\Framework\TestCase;

/**
 * Not a unit test — it reads the source instead of calling anything.
 *
 * The address pattern is an operator setting. A second place that knows how a
 * product address is shaped makes that setting a lie, and the lie stays
 * invisible until somebody changes the pattern and only half the links move.
 * No assertion about behaviour can catch that, because the wrong code works
 * perfectly until the day it does not.
 */
class AddressShapeIsOwnedInOnePlaceTest extends TestCase
{
    /**
     * Everything allowed to know what a product address looks like.
     *
     * Adding a file here is a decision, not a formality: it means one more
     * place that has to be changed when the shape changes.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        // the pattern itself
        'src/Seo/ProductPathTemplate.php',
        // builds the route from it
        'src/Controller/Page/ProductPageController.php',
        // refuses a pattern that cannot address products
        'src/EventListener/DataContainer/PathTemplateListener.php',
        /*
         * The same refusal after the fact: a pattern written past the mask is
         * reported on the start page. It only asks whether a stored pattern is
         * valid, by the rules above — it builds no address.
         */
        'src/Seo/DbalPathTemplateIssueSource.php',
        /*
         * Renders YESTERDAY's addresses before a new pattern replaces them.
         * ProductUrlGenerator cannot help: it answers with today's pattern, and
         * this is the one place that needs the previous one.
         */
        'src/Seo/RedirectRecorder.php',
        /*
         * Has to know which placeholders a pattern uses, so it can pass only
         * those. Handing the generator all four put the unused ones into the
         * query string of a live canonical address.
         */
        'src/Seo/ProductUrlGenerator.php',
    ];

    public function testOnlyTheseFilesKnowHowAProductAddressIsShaped(): void
    {
        $offenders = [];

        foreach ($this->sourceFiles() as $relative => $contents) {
            if (\in_array($relative, self::ALLOWED, true)) {
                continue;
            }

            if ($this->uses($contents)) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These files build a product address themselves. Ask Seo\ProductUrlGenerator instead — '
            .'otherwise the address pattern on the product page stops being the single source of truth.',
        );
    }

    public function testTheAllowedFilesStillExist(): void
    {
        // An allow-list that names files nobody deleted is a list that has
        // stopped being read. If one moves, this fails rather than silently
        // permitting nothing.
        foreach (self::ALLOWED as $relative) {
            self::assertFileExists($this->root().'/'.$relative);
        }
    }

    /**
     * Detects use, not mention.
     *
     * A comment pointing at the class is exactly what we want people to write —
     * the first version of this test flagged two files for saying "see
     * Seo\ProductPathTemplate" in a docblock. Real use needs an import, a static
     * call or a constructor, and all three are visible in the text.
     */
    private function uses(string $contents): bool
    {
        return (bool) preg_match(
            '/(use\s+[\\\\\w]*ProductPathTemplate\s*;|ProductPathTemplate::|new\s+ProductPathTemplate)/',
            $contents,
        );
    }

    /**
     * @return iterable<string, string>
     */
    private function sourceFiles(): iterable
    {
        $root = $this->root();

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root.'/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), \strlen($root) + 1));

            yield $relative => (string) file_get_contents($file->getPathname());
        }
    }

    private function root(): string
    {
        return \dirname(__DIR__, 2);
    }
}
