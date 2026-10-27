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
 * Builds a real Symfony container (not by hand like ShippingMethodTypesTest
 * and PaymentHandlersTest) and checks that #[AutoconfigureTag] on the
 * interfaces together with #[AutowireLocator(..., defaultIndexMethod: 'getType')]
 * on the registries really work together. The hand-built tests would not
 * notice a missing defaultIndexMethod.
 *
 * The prototype definition here mirrors `_defaults` from config/services.yaml
 * (autowire, autoconfigure) and has to follow it when that changes.
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
        // The test provider is registered, but not visible without the environment variable.
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

        // Only the three folders the registries concern (the task, not the
        // whole bundle scan). Value objects and enums below them (e.g.
        // PaymentContext with required scalar parameters, AttemptState as an
        // enum) are registered here too, but do not break compilation: they
        // stay unused and Symfony's RemoveUnusedDefinitionsPass removes them
        // before anything would have to instantiate them. Checked by running
        // the same registration without the exclude list, which went through.
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

        // The application's router (TestPaymentHandler, C-11). Synthetic: it
        // is never needed here, because the test provider is not handed out
        // at all without CONSHO_TEST_PAYMENT.
        $container->register(UrlGeneratorInterface::class)->setSynthetic(true);

        $container->getDefinition(ShippingMethodTypes::class)->setPublic(true);
        $container->getDefinition(PaymentHandlers::class)->setPublic(true);

        $container->compile();

        return $container;
    }
}
