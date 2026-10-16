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
        // Ohne das lädt eine Managed Edition die Attribut-Routen des Bundles
        // gar nicht — die Route antwortet dann mit 404 statt zu arbeiten.
        //
        // Über class_implements() und nicht assertInstanceOf(): der Typ steht
        // in der Klassendeklaration, PHPStan verengt ihn und meldet die
        // Behauptung als immer wahr. Die Liste der Schnittstellen zur Laufzeit
        // zu lesen prüft dasselbe, ohne dass die Prüfung verschwindet.
        self::assertContains(RoutingPluginInterface::class, class_implements(new Plugin()) ?: []);
    }

    public function testDieRoutenKommenAusSrcControllerUeberDenAttributLader(): void
    {
        // Der Ladepfad ist der Ordner der Controller, der Ladertyp „attribute".
        // Beides zeigt auf denselben Ordner: resolve() sucht den Lader anhand
        // des Typs, load() wendet ihn dann an.
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
        // resolve() liefert false, wenn kein Lader den Typ "attribute"
        // handhaben kann. Ohne diesen Zweig würde load() auf false aufgerufen
        // und die Route mit einem Fatal Error sterben, statt die Sammlung
        // einfach leer zu lassen.
        $resolver = $this->createMock(LoaderResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(false);

        self::assertNull((new Plugin())->getRouteCollection($resolver, $this->createMock(KernelInterface::class)));
    }
}
