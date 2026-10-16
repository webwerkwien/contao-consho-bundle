<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductParentListener;

class ProductParentListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = [
            'variantSelf' => 'itself',
            'variantHasVariants' => 'has %s of its own',
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testAcceptsAParentThatIsAnotherProduct(): void
    {
        $listener = new ProductParentListener($this->connectionCounting(0));

        self::assertSame(7, $listener->save(7, $this->dc(3)));
    }

    public function testAnEmptyParentMeansNoVariant(): void
    {
        $listener = new ProductParentListener($this->connectionCounting(0));

        self::assertSame(0, $listener->save('', $this->dc(3)));
    }

    public function testRefusesAProductAsItsOwnParent(): void
    {
        $listener = new ProductParentListener($this->connectionCounting(0));

        $this->expectExceptionMessage('itself');

        $listener->save(3, $this->dc(3));
    }

    public function testRefusesAProductThatHasVariantsOfItsOwn(): void
    {
        // Making it a variant would leave its own variants without a main
        // article — the options list cannot rule this out on its own.
        $listener = new ProductParentListener($this->connectionCounting(2));

        $this->expectExceptionMessage('has 2 of its own');

        $listener->save(7, $this->dc(3));
    }

    private function connectionCounting(int $variants): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($variants);

        return $connection;
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnMap([['id', $id]]);

        return $dc;
    }
}
