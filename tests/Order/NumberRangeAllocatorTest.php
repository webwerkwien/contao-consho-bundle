<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeAllocator;
use Webwerkwien\ContaoConshoBundle\Order\NumberRangeNotConfigured;

class NumberRangeAllocatorTest extends TestCase
{
    public function testFormatsTheNextNumberWithPrefixAndPadding(): void
    {
        $connection = $this->connectionReturning(['id' => 1, 'prefix' => 'RE-', 'padding' => 5, 'current' => 41]);

        $allocator = new NumberRangeAllocator($connection);

        self::assertSame('RE-00042', $allocator->next(1, 'invoice'));
    }

    public function testWorksWithoutAPrefix(): void
    {
        $connection = $this->connectionReturning(['id' => 1, 'prefix' => '', 'padding' => 4, 'current' => 0]);

        $allocator = new NumberRangeAllocator($connection);

        self::assertSame('0001', $allocator->next(1, 'order'));
    }

    public function testDoesNotPadWhenTheNumberIsLonger(): void
    {
        // Padding is a minimum width, not a maximum. A shop that outgrows its
        // padding keeps counting rather than wrapping or truncating.
        $connection = $this->connectionReturning(['id' => 1, 'prefix' => '', 'padding' => 3, 'current' => 99_999]);

        $allocator = new NumberRangeAllocator($connection);

        self::assertSame('100000', $allocator->next(1, 'order'));
    }

    public function testLocksTheRowWhileAllocating(): void
    {
        /*
         * The point of the whole class. Two simultaneous orders must never
         * receive the same document number — a duplicate there is a tax
         * problem, not a blemish. SELECT MAX(…) + 1 would look just as correct
         * and would hand out duplicates under load.
         */
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('beginTransaction');
        $connection->expects(self::once())->method('commit');
        $connection
            ->expects(self::once())
            ->method('fetchAssociative')
            ->with(self::stringContains('FOR UPDATE'))
            ->willReturn(['id' => 1, 'prefix' => '', 'padding' => 4, 'current' => 0])
        ;
        $connection->expects(self::once())->method('executeStatement');

        $allocator = new NumberRangeAllocator($connection);

        $allocator->next(1, 'order');
    }

    public function testRollsBackWhenWritingFails(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 1, 'prefix' => '', 'padding' => 4, 'current' => 0]);
        $connection->method('executeStatement')->willThrowException(new \RuntimeException('boom'));
        $connection->expects(self::once())->method('rollBack');

        $allocator = new NumberRangeAllocator($connection);

        $this->expectException(\RuntimeException::class);

        $allocator->next(1, 'order');
    }

    public function testThrowsWhenNoRangeIsConfigured(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(false);

        $allocator = new NumberRangeAllocator($connection);

        $this->expectException(NumberRangeNotConfigured::class);

        $allocator->next(1, 'invoice');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function connectionReturning(array $row): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn($row);

        return $connection;
    }
}
