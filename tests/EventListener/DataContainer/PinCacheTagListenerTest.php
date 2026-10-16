<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Cache\CacheTagManager;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PinCacheTagListener;

/**
 * Nur der Papierkorb. Speichern, Umschalten und Löschen entwertet Contao selbst:
 * DC_Table setzt `config.ptable` aus der Zeile (findPtable()), und
 * addPtableTags() hängt damit `contao.db.tl_content.<Liste>` an.
 *
 * ⚠️ Eine frühere Fassung ergänzte diesen Tag noch einmal, und ihr Test gab
 * die Kern-Tags OHNE ihn vor — eine Eingabe, die der Kern nie liefert. Die
 * contao-ai-Session hat es am 2026-09-13 aufgedeckt.
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

        // pid 10 meint hier den Artikel 10, nicht das Element 10.
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
