<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;

/**
 * Baut einen echten Symfony-Container (nicht von Hand wie ShippingMethodTypesTest
 * und PaymentHandlersTest) und prüft, dass #[AutoconfigureTag] auf den
 * Schnittstellen zusammen mit #[AutowireLocator(..., defaultIndexMethod: 'getType')]
 * auf den Registern tatsächlich zusammenspielen. Die von Hand gebauten Tests
 * würden ein fehlendes defaultIndexMethod nicht bemerken.
 *
 * Die Prototyp-Definition hier bildet `_defaults` aus config/services.yaml
 * nach (autowire, autoconfigure) und muss dem folgen, wenn sich das dort ändert.
 */
class MethodRegistriesContainerTest extends TestCase
{
    public function testDerContainerFuelltBeideRegisterUeberGetType(): void
    {
        $container = $this->buildContainer();

        /** @var ShippingMethodTypes $shippingTypes */
        $shippingTypes = $container->get(ShippingMethodTypes::class);
        /** @var PaymentHandlers $paymentHandlers */
        $paymentHandlers = $container->get(PaymentHandlers::class);

        self::assertSame(['flat_rate', 'weight_rate'], $shippingTypes->keys());
        // Der Test-Anbieter ist registriert, aber ohne Umgebungsvariable nicht zu sehen.
        self::assertSame(['cod', 'invoice', 'prepayment'], $paymentHandlers->keys());
        self::assertFalse($paymentHandlers->has('test'));
        self::assertInstanceOf(FlatRateShipping::class, $shippingTypes->get('flat_rate'));
        self::assertInstanceOf(PrepaymentHandler::class, $paymentHandlers->get('prepayment'));
    }

    private function buildContainer(): ContainerBuilder
    {
        $srcDir = \dirname(__DIR__, 2).'/src';

        $container = new ContainerBuilder();
        $loader = new PhpFileLoader($container, new FileLocator($srcDir));

        $prototype = new Definition();
        $prototype->setAutowired(true)->setAutoconfigured(true)->setPublic(false);

        // Nur die drei Ordner, die die Register betreffen (Auftrag, nicht der
        // ganze Bundle-Scan). Wertobjekte und Enums darunter (z. B.
        // PaymentContext mit Pflicht-Skalarparametern, AttemptState als Enum)
        // werden hier mitregistriert, sprengen die Kompilierung aber nicht:
        // sie bleiben ungenutzt und Symfonys RemoveUnusedDefinitionsPass
        // entfernt sie, bevor irgendetwas sie instanziieren müsste. Geprüft,
        // indem dieselbe Registrierung ohne Exclude-Liste anstandslos lief.
        $loader->registerClasses(
            $prototype,
            'Webwerkwien\\ContaoConshoBundle\\Shipping\\',
            $srcDir.'/Shipping/*',
        );
        $loader->registerClasses(
            $prototype,
            'Webwerkwien\\ContaoConshoBundle\\Payment\\',
            $srcDir.'/Payment/*',
        );
        $loader->registerClasses(
            $prototype,
            'Webwerkwien\\ContaoConshoBundle\\Checkout\\',
            $srcDir.'/Checkout/*',
        );

        // Der Router der Anwendung (TestPaymentHandler, C-11). Synthetisch: Er
        // wird hier nie gebraucht, weil der Test-Anbieter ohne
        // CONSHO_TEST_PAYMENT gar nicht erst herausgegeben wird.
        $container->register(UrlGeneratorInterface::class)->setSynthetic(true);

        $container->getDefinition(ShippingMethodTypes::class)->setPublic(true);
        $container->getDefinition(PaymentHandlers::class)->setPublic(true);

        $container->compile();

        return $container;
    }
}
