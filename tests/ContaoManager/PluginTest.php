<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\ContaoManager;

use Contao\ManagerPlugin\Routing\RoutingPluginInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolverInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Webwerkwien\ContaoConshoBundle\ContaoManager\Plugin;

class PluginTest extends TestCase
{
    public function testDasPluginLiefertRouten(): void
    {
        // Without this a Managed Edition does not load the bundle's attribute
        // routes at all — the route then answers with 404 instead of working.
        //
        // Via class_implements() and not assertInstanceOf(): the type is in
        // the class declaration, PHPStan narrows it and reports the assertion
        // as always true. Reading the list of interfaces at runtime checks the
        // same thing without the check disappearing.
        self::assertContains(RoutingPluginInterface::class, class_implements(new Plugin()) ?: []);
    }

    public function testDieRoutenKommenAusSrcControllerUeberDenAttributLader(): void
    {
        // The resource is the controllers' folder, the loader type "attribute".
        // Both point to the same folder: resolve() finds the loader by the
        // type, load() then applies it.
        $collection = new RouteCollection();
        $collection->add('consho_cart_add', new Route('/_consho/cart/add'));

        $expectedPath = realpath(__DIR__.'/../../src/Controller');
        self::assertIsString($expectedPath);

        $loader = $this->createMock(LoaderInterface::class);
        $loader
            ->expects(self::once())
            ->method('load')
            ->with(self::callback(static fn (mixed $path): bool => \is_string($path) && realpath($path) === $expectedPath), 'attribute')
            ->willReturn($collection)
        ;

        $resolver = $this->createMock(LoaderResolverInterface::class);
        $resolver
            ->expects(self::once())
            ->method('resolve')
            ->with(self::callback(static fn (mixed $path): bool => \is_string($path) && realpath($path) === $expectedPath), 'attribute')
            ->willReturn($loader)
        ;

        self::assertSame(
            $collection,
            (new Plugin())->getRouteCollection($resolver, $this->createMock(KernelInterface::class)),
        );
    }

    public function testKeinLaderGefundenLiefertNull(): void
    {
        // resolve() returns false if no loader can handle the type "attribute".
        // Without this branch load() would be called on false and the route
        // would die with a fatal error instead of simply leaving the collection
        // empty.
        $resolver = $this->createMock(LoaderResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(false);

        self::assertNull((new Plugin())->getRouteCollection($resolver, $this->createMock(KernelInterface::class)));
    }
}
