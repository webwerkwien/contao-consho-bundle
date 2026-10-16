<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Model\Collection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantResolver;
use Webwerkwien\ContaoConshoBundle\Model\ProductModel;

class VariantResolverTest extends TestCase
{
    public function testAProductWithoutAParentIsAMainProduct(): void
    {
        $resolver = new VariantResolver($this->createMock(ContaoFramework::class));
        $product = $this->product(id: 5, pid: 0);

        self::assertTrue($resolver->isMainProduct($product));
        self::assertFalse($resolver->isVariant($product));
    }

    public function testAProductWithAParentIsAVariant(): void
    {
        $resolver = new VariantResolver($this->createMock(ContaoFramework::class));
        $product = $this->product(id: 9, pid: 12);

        self::assertTrue($resolver->isVariant($product));
        self::assertFalse($resolver->isMainProduct($product));
    }

    public function testFindsTheVariantsOfAMainProduct(): void
    {
        $variant = $this->product(id: 9, pid: 5);
        $collection = new Collection([$variant], 'tl_consho_product');

        $resolver = new VariantResolver($this->frameworkFinding('findByPid', [5], $collection));

        self::assertSame([$variant], $resolver->findVariants($this->product(id: 5, pid: 0)));
    }

    public function testAVariantHasNoVariantsOfItsOwn(): void
    {
        // Variants are never nested. Guarding here rather than relying on an
        // empty result keeps the rule visible — and saves a query. Without it,
        // a variant of a variant would appear sooner or later, and every
        // evaluation assuming two levels would be silently wrong.
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');

        $resolver = new VariantResolver($framework);

        self::assertSame([], $resolver->findVariants($this->product(id: 9, pid: 5)));
    }

    public function testFindVariantsReturnsAnEmptyArrayWhenThereAreNone(): void
    {
        $resolver = new VariantResolver($this->frameworkFinding('findByPid', [5], null));

        self::assertSame([], $resolver->findVariants($this->product(id: 5, pid: 0)));
    }

    public function testFindsTheMainProductOfAVariant(): void
    {
        $main = $this->product(id: 5, pid: 0);

        $resolver = new VariantResolver($this->frameworkFinding('findByPk', [5], $main));

        self::assertSame($main, $resolver->findMainProduct($this->product(id: 9, pid: 5)));
    }

    public function testAMainProductHasNoMainProduct(): void
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects(self::never())->method('getAdapter');

        $resolver = new VariantResolver($framework);

        self::assertNull($resolver->findMainProduct($this->product(id: 5, pid: 0)));
    }

    public function testReturnsNullWhenTheMainProductIsGone(): void
    {
        $resolver = new VariantResolver($this->frameworkFinding('findByPk', [5], null));

        self::assertNull($resolver->findMainProduct($this->product(id: 9, pid: 5)));
    }

    private function product(int $id, int $pid): ProductModel
    {
        $product = $this->createMock(ProductModel::class);
        $product->method('__get')->willReturnMap([
            ['id', $id],
            ['pid', $pid],
        ]);

        return $product;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function frameworkFinding(string $method, array $arguments, ?object $result): ContaoFramework
    {
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->with($method, $arguments)->willReturn($result);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(ProductModel::class)->willReturn($adapter);

        return $framework;
    }
}
