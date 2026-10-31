<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dev;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * What lives in src/Dev seeds and times catalogues past versions and recycle
 * bin. It is loaded as a service only in the dev environment, like the two
 * commands that use it — a class added later must say so too.
 */
final class DevelopmentOnlyTest extends TestCase
{
    public function testJedeKlasseInDevGibtEsNurInDerDevUmgebung(): void
    {
        $files = glob(\dirname(__DIR__, 2).'/src/Dev/*.php') ?: [];

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $class = 'Webwerkwien\\ContaoConshoBundle\\Dev\\'.basename($file, '.php');
            $environments = array_map(
                static fn (\ReflectionAttribute $attribute): string => $attribute->newInstance()->env,
                (new \ReflectionClass($class))->getAttributes(When::class),
            );

            $this->assertSame(['dev'], $environments, $class);
        }
    }
}
