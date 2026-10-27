<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PinCacheTagListener;

/**
 * Only the recycle bin. Saving, toggling and deleting are invalidated by
 * Contao itself: DC_Table sets `config.ptable` from the row (findPtable()),
 * and addPtableTags() thereby adds `contao.db.tl_content.<list>`.
 *
 * ⚠️ An earlier version added this tag once more, and its test preset the
 * core tags WITHOUT it — an input that the core never delivers.
 */
class PinCacheTagListenerTest extends TestCase
{
    public function testRestoringAPinFromTheRecycleBinInvalidatesItsListing(): void
    {
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::once())->method('invalidateTags')->with(['contao.db.tl_content.10']);

        (new PinCacheTagListener($tags))->onUndo('tl_content', ['id' => 20, 'pid' => 10, 'ptable' => 'tl_content', 'type' => 'consho_product_pin'], $this->dc());
    }

    public function testRestoringAnyOtherElementIsLeftToTheCore(): void
    {
        $tags = $this->createMock(CacheTagManager::class);
        $tags->expects(self::never())->method('invalidateTags');

        $listener = new PinCacheTagListener($tags);
        $listener->onUndo('tl_content', ['id' => 21, 'pid' => 11, 'ptable' => 'tl_content', 'type' => 'text'], $this->dc());

        // pid 10 here means article 10, not element 10.
        $listener->onUndo('tl_content', ['id' => 22, 'pid' => 10, 'ptable' => 'tl_article', 'type' => 'consho_product_pin'], $this->dc());
    }

    public function testHangsOnTheRecycleBinOnly(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(PinCacheTagListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                $targets[] = $attribute->newInstance()->target;
            }
        }

        self::assertSame(['config.onundo'], $targets);
    }

    private function dc(): DC_Table
    {
        return (new \ReflectionClass(DC_Table::class))->newInstanceWithoutConstructor();
    }
}
