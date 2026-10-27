<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterCountsInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterPanelBuilder;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;

/**
 * The docking point for the filter counts: a module for large catalogues
 * must be able to replace the counting without changing the core. That
 * holds only as long as FilterPanelBuilder alone knows the interface and
 * the alias sits where a module redirects it.
 */
class FilterCountsSeamTest extends TestCase
{
    public function testTheFilterPanelAsksOnlyTheInterface(): void
    {
        $types = array_map(
            static fn (\ReflectionParameter $parameter): string => (string) $parameter->getType(),
            (new \ReflectionMethod(FilterPanelBuilder::class, '__construct'))->getParameters(),
        );

        self::assertContains(FilterCountsInterface::class, $types);
        self::assertNotContains(ProductQuery::class, $types, 'Mit ProductQuery im Konstruktor ließe sich die Zählung nicht austauschen.');
    }

    public function testTheAliasIsWrittenDownWhereAModuleOverridesIt(): void
    {
        $services = Yaml::parseFile(__DIR__.'/../../config/services.yaml')['services'] ?? [];

        self::assertSame(
            ['alias' => ProductQuery::class],
            $services[FilterCountsInterface::class] ?? null,
        );
    }
}
