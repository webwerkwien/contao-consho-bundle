<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DC_Table;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\GtinListener;

class GtinListenerTest extends TestCase
{
    private GtinListener $listener;

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG']['CONSHO']['errors'] = ['gtinInvalid' => 'invalid: %s'];

        $this->listener = new GtinListener();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testStoresTheNumberWithoutSeparators(): void
    {
        // The number is a key: a lookup for "4006381333931" has to find the
        // article somebody entered as "4 006381 333931".
        self::assertSame('4006381333931', $this->save('4 006381 333931'));
    }

    public function testKeepsAnEmptyFieldEmpty(): void
    {
        self::assertSame('', $this->save('  '));
    }

    public function testRefusesAWrongCheckDigit(): void
    {
        // Refused, not reported: a wrong GTIN is not an incomplete shop, it is
        // a statement about a different article.
        $this->expectExceptionMessage('invalid: 4006381333930');

        $this->save('4006381333930');
    }

    private function save(string $value): string
    {
        return ($this->listener)($value, $this->createMock(DC_Table::class));
    }
}
