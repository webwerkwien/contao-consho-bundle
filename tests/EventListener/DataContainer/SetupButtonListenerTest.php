<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\SetupButtonListener;

final class SetupButtonListenerTest extends TestCase
{
    public function testDieOperationFuehrtZurEinrichtungDiesesShops(): void
    {
        $operation = $this->operation();
        (new SetupButtonListener($this->router(), $this->security(true)))($operation);

        self::assertSame('/contao?do=consho_dashboard&view=setup#shop-3', $operation->getUrl());
        self::assertNull($operation->getHtml());
    }

    public function testOhneRechtAufsDashboardAusgeblendet(): void
    {
        // Review I, L10: sonst endet der Klick bei „Zugriff verweigert".
        $operation = $this->operation();
        (new SetupButtonListener($this->router(), $this->security(false)))($operation);

        self::assertSame('', $operation->getHtml());
    }

    private function operation(): DataContainerOperation
    {
        return new DataContainerOperation('setup', ['icon' => 'wrench.svg', 'label' => ['Einrichtung', '%s']], ['id' => 3], $this->createStub(DataContainer::class));
    }

    private function router(): UrlGeneratorInterface
    {
        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/contao?'.http_build_query($parameters));

        return $router;
    }

    private function security(bool $dashboard): Security
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $attribute, mixed $module = null): bool => $dashboard && ContaoCorePermissions::USER_CAN_ACCESS_MODULE === $attribute && 'consho_dashboard' === $module);

        return $security;
    }
}
