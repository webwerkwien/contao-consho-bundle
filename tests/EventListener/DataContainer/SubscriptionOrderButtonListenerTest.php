<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SubscriptionOrderButtonListener;

/**
 * Abo-8, P80: der Link „Kaufbestellung" in der Abo-Liste.
 */
final class SubscriptionOrderButtonListenerTest extends TestCase
{
    public function testFuehrtZurKaufbestellung(): void
    {
        $operation = $this->operation(['id' => 40, 'firstOrder' => 7]);

        $this->listener()($operation);

        self::assertSame('/contao?do=consho_order&act=edit&id=7', $operation->getUrl());
    }

    public function testOhneKaufbestellungAbgeschaltet(): void
    {
        $operation = $this->operation(['id' => 40, 'firstOrder' => 0]);

        $this->listener()($operation);

        self::assertNull($operation->getUrl());
        self::assertSame('parent--disabled.svg', $operation['icon'], 'als abgeschaltetes Symbol');
    }

    private function listener(): SubscriptionOrderButtonListener
    {
        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $params): string => '/contao?'.http_build_query($params));

        return new SubscriptionOrderButtonListener($router);
    }

    /**
     * @param array<string, mixed> $record
     */
    private function operation(array $record): DataContainerOperation
    {
        return new DataContainerOperation('order', ['href' => '', 'icon' => 'parent.svg', 'label' => 'Kaufbestellung', 'title' => 'Kaufbestellung'], $record, $this->createStub(DataContainer::class));
    }
}
