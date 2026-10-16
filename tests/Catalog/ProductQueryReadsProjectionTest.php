<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductQuery;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;

/**
 * ProductQuery liest die Projektion der Sichtbarkeit, nicht die Regeln
 * (B-10, Spezifikation 5.7).
 *
 * Die Regeln leben in VisibilityCriteria und VisibilityRows. Hängt ProductQuery
 * wieder an VisibilityCriteria, rechnet die Liste die Sichtbarkeit je Produkt
 * erneut selbst — der Scan aus B-9 wäre zurück, und keine Erwartung in
 * ProductQueryTest fiele dabei auf, weil beide Wege dieselben Produkte finden.
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
