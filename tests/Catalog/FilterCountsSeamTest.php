<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterCountsInterface;
use Webwerkwien\ContaoConshoBundle\Catalog\FilterPanelBuilder;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;

/**
 * Die Andockstelle für die Filterzahlen (Entscheidung Michael, 2026-09-14):
 * Ein Modul für große Kataloge muss die Zählung austauschen können, ohne den
 * Kern zu ändern. Das hält nur, solange FilterPanelBuilder allein die
 * Schnittstelle kennt und der Alias dort steht, wo ein Modul ihn umbiegt.
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
