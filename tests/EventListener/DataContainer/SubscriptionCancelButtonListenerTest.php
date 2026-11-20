<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionCancelButtonListener;

/**
 * Abo-9, P128: "Kündigen" only where there is something to cancel.
 */
final class SubscriptionCancelButtonListenerTest extends TestCase
{
    public function testLaufendesAboKuendbar(): void
    {
        $operation = $this->operation(['id' => 4, 'state' => 'active', 'endsAt' => null]);

        (new SubscriptionCancelButtonListener())($operation);

        self::assertSame('bundles/contaoconsho/icons/cancel.svg', $operation['icon']);
    }

    public function testMitEndeOderBeendetAbgeschaltet(): void
    {
        foreach ([['state' => 'cancelled', 'endsAt' => 1_900_000_000], ['state' => 'ended', 'endsAt' => 0]] as $record) {
            $operation = $this->operation(['id' => 4] + $record);

            (new SubscriptionCancelButtonListener())($operation);

            self::assertSame('bundles/contaoconsho/icons/cancel--disabled.svg', $operation['icon'], $record['state']);
            self::assertNull($operation->getUrl());
        }
    }

    /**
     * @param array<string, mixed> $record
     */
    private function operation(array $record): DataContainerOperation
    {
        return new DataContainerOperation('cancel', ['href' => 'key=cancelSubscription', 'icon' => 'bundles/contaoconsho/icons/cancel.svg', 'label' => 'Kündigen', 'title' => 'Kündigen'], $record, $this->createStub(DataContainer::class));
    }
}
