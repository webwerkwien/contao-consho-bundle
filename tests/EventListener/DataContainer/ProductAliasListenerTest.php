<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Slug\Slug;
use Contao\DC_Table;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\ProductAliasListener;

class ProductAliasListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['ERR']['aliasNumeric'] = 'Numeric alias %s';
        $GLOBALS['TL_LANG']['ERR']['aliasExists'] = 'Alias %s exists';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testGeneratesAnAliasFromTheTitleWhenTheFieldIsEmpty(): void
    {
        $listener = $this->listener(new AliasFactory(
            $this->slugReturning('schwedenbitter'),
            $this->connectionCounting(0),
        ));

        self::assertSame('schwedenbitter', $listener('', $this->dc(id: 1, title: 'Schwedenbitter')));
    }

    public function testReadsTheTitleThatIsBeingSubmitted(): void
    {
        // The stored row is still empty on a newly created product. The title
        // only exists in the submitted values, which is why the listener asks
        // for the merged record and not for the stored one.
        $slug = $this->createMock(Slug::class);
        $slug
            ->expects(self::once())
            ->method('generate')
            ->with('Schwedenbitter')
            ->willReturn('schwedenbitter')
        ;

        $listener = $this->listener(new AliasFactory($slug, $this->connectionCounting(0)));

        $listener('', $this->dc(id: 1, title: 'Schwedenbitter'));
    }

    public function testKeepsAnAliasTheEditorTypedIn(): void
    {
        $slug = $this->createMock(Slug::class);
        $slug->expects(self::never())->method('generate');

        $listener = $this->listener(new AliasFactory($slug, $this->connectionCounting(0)));

        self::assertSame('bitterer-schwede', $listener('bitterer-schwede', $this->dc(id: 1, title: 'Schwedenbitter')));
    }

    public function testTrimsAnAliasTheEditorTypedIn(): void
    {
        $listener = $this->listener(new AliasFactory(
            $this->slugReturning('unused'),
            $this->connectionCounting(0),
        ));

        self::assertSame('bitterer-schwede', $listener('  bitterer-schwede  ', $this->dc(id: 1, title: 'Schwedenbitter')));
    }

    public function testRejectsAPurelyNumericAlias(): void
    {
        // A numeric alias would collide with Contao's own ID-based routing.
        $listener = $this->listener(new AliasFactory(
            $this->slugReturning('unused'),
            $this->connectionCounting(0),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Numeric alias 42');

        $listener('42', $this->dc(id: 1, title: 'Schwedenbitter'));
    }

    public function testRejectsAnAliasThatAnotherProductAlreadyUses(): void
    {
        $listener = $this->listener(new AliasFactory(
            $this->slugReturning('unused'),
            $this->connectionCounting(1),
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Alias bitterer-schwede exists');

        $listener('bitterer-schwede', $this->dc(id: 1, title: 'Schwedenbitter'));
    }

    public function testExcludesTheProductItselfFromTheDuplicateCheck(): void
    {
        // Saving a product a second time must not report its own alias as taken.
        $connection = $this->createMock(Connection::class);
        $connection
            ->expects(self::once())
            ->method('fetchOne')
            ->with(self::anything(), ['bitterer-schwede', 7])
            ->willReturn(0)
        ;

        $listener = $this->listener(new AliasFactory($this->slugReturning('unused'), $connection));

        self::assertSame('bitterer-schwede', $listener('bitterer-schwede', $this->dc(id: 7, title: 'Schwedenbitter')));
    }

    public function testHandsTheDuplicateCheckToTheSlugGenerator(): void
    {
        // Two products may well be called the same. Contao's slug generator
        // resolves that by counting up, but only if it is given a way to ask.
        $slug = $this->createMock(Slug::class);
        $slug
            ->expects(self::once())
            ->method('generate')
            ->with('Schwedenbitter', [], self::isType('callable'))
            ->willReturn('schwedenbitter-2')
        ;

        $listener = $this->listener(new AliasFactory($slug, $this->connectionCounting(0)));

        self::assertSame('schwedenbitter-2', $listener('', $this->dc(id: 2, title: 'Schwedenbitter')));
    }

    /**
     * Die alte Adresse zeichnet ProductAddressListener für den ganzen
     * Speichervorgang auf — geprüft in Tests\Seo\AliasRedirectTest und
     * Tests\Seo\ProductAddressRedirectTest gegen SQLite.
     */
    private function listener(AliasFactory $aliases): ProductAliasListener
    {
        return new ProductAliasListener($aliases);
    }

    private function slugReturning(string $alias): Slug
    {
        $slug = $this->createMock(Slug::class);
        $slug->method('generate')->willReturn($alias);

        return $slug;
    }

    private function connectionCounting(int $matches): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($matches);

        return $connection;
    }

    private function dc(int $id, string $title): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnMap([['id', $id]]);
        $dc->method('getActiveRecord')->willReturn(['id' => $id, 'title' => $title]);

        return $dc;
    }
}
