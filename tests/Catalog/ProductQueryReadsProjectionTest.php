<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;

/**
 * ProductQuery reads the visibility projection, not the rules
 * (B-10, specification 5.7).
 *
 * The rules live in VisibilityCriteria and VisibilityRows. If ProductQuery
 * depends on VisibilityCriteria again, the list recomputes visibility per
 * product itself — the scan from B-9 would be back, and no expectation in
 * ProductQueryTest would notice, because both paths find the same products.
 */
class ProductQueryReadsProjectionTest extends TestCase
{
    public function testTheConstructorTakesNoVisibilityCriteria(): void
    {
        $constructor = (new \ReflectionClass(ProductQuery::class))->getConstructor();

        self::assertNotNull($constructor);

        foreach ($constructor->getParameters() as $parameter) {
            $type = $parameter->getType();
            $names = $type instanceof \ReflectionNamedType
                ? [$type->getName()]
                : array_map(static fn (\ReflectionType $t): string => $t instanceof \ReflectionNamedType ? $t->getName() : (string) $t, $type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType ? $type->getTypes() : []);

            self::assertNotContains(VisibilityCriteria::class, $names, '$'.$parameter->getName());
        }
    }

    public function testTheSourceDoesNotImportVisibilityCriteria(): void
    {
        $file = (new \ReflectionClass(ProductQuery::class))->getFileName();

        self::assertIsString($file);

        $source = file_get_contents($file);

        self::assertIsString($source);
        self::assertDoesNotMatchRegularExpression('/^use\s+[\\\\\w]*\\\\VisibilityCriteria\s*;/m', $source);
    }
}
