<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\OrderIsLocked;
use Webwerkwien\ContaoConshoBundle\Order\OrderLock;

class OrderLockTest extends TestCase
{
    private OrderLock $lock;

    protected function setUp(): void
    {
        $this->lock = new OrderLock();
    }

    public function testAFreshOrderIsMutable(): void
    {
        // Before a document exists and before money has moved there is nothing
        // to protect — the quantity may still change.
        self::assertFalse($this->lock->isLocked(false, false, 0));
    }

    public function testADocumentLocksTheOrder(): void
    {
        self::assertTrue($this->lock->isLocked(false, false, 1));
    }

    public function testAPaymentLocksTheOrder(): void
    {
        self::assertTrue($this->lock->isLocked(true, false, 0));
    }

    public function testAStatusFlaggedAsLockingLocksTheOrder(): void
    {
        self::assertTrue($this->lock->isLocked(false, true, 0));
    }

    public function testAssertMutablePassesForAFreshOrder(): void
    {
        $this->lock->assertMutable(false, false, 0);

        self::assertFalse($this->lock->isLocked(false, false, 0));
    }

    public function testAssertMutableThrowsOnceADocumentExists(): void
    {
        $this->expectException(OrderIsLocked::class);
        $this->expectExceptionMessage('document');

        $this->lock->assertMutable(false, false, 3);
    }

    public function testTheReasonNamesTheStrongestCause(): void
    {
        // With several reasons at once the document is the one to report: it is
        // the one that cannot be undone by deleting a row.
        $this->expectException(OrderIsLocked::class);
        $this->expectExceptionMessage('document');

        $this->lock->assertMutable(true, true, 1);
    }
}
