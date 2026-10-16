<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryState;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalance;
use Webwerkwien\ContaoConshoBundle\Order\PaymentState;
use Webwerkwien\ContaoConshoBundle\Order\StatusFlags;
use Webwerkwien\ContaoConshoBundle\Order\StatusRequirement;

final class StatusRequirementTest extends TestCase
{
    public function testAbgeschlossenVerlangtBeideSalden(): void
    {
        $unmet = StatusRequirement::unmet(new StatusFlags(1, 1, 'Fertig', isCompleted: true), $this->balance(paid: '0', open: [1 => '1']));

        self::assertSame([StatusRequirement::PaymentSettled, StatusRequirement::DeliverySettled], $unmet);
    }

    public function testVersendetVerlangtNurDieLieferung(): void
    {
        $unmet = StatusRequirement::unmet(new StatusFlags(1, 1, 'Versendet', isShipped: true), $this->balance(paid: '0', open: []));

        self::assertSame([], $unmet);
    }

    public function testStorniertVerlangtDassNichtsVersandtIst(): void
    {
        $unmet = StatusRequirement::unmet(new StatusFlags(1, 1, 'Storniert', isCancelled: true), $this->balance(paid: '0', open: [], anyShipped: true));

        self::assertSame([StatusRequirement::NothingShipped], $unmet);
    }

    public function testOhneFlagsGibtEsKeineVorbedingung(): void
    {
        self::assertSame([], StatusRequirement::unmet(new StatusFlags(1, 1, 'In Klärung'), $this->balance(paid: '0', open: [1 => '1'])));
    }

    public function testAbgeschlossenHaeltDieBestellungFest(): void
    {
        self::assertTrue((new StatusFlags(1, 1, 'Fertig', isCompleted: true))->holds());
        self::assertFalse((new StatusFlags(1, 1, 'Versendet', isShipped: true))->holds());
    }

    /**
     * @param array<int, string> $open
     */
    private function balance(string $paid, array $open, bool $anyShipped = false): OrderBalance
    {
        return new OrderBalance('100', $paid, PaymentState::Open, DeliveryState::Open, $open, $anyShipped);
    }
}
