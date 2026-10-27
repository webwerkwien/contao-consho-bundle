<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Checkout\UnknownMethodType;
use Webwerkwien\ContaoConshoBundle\Shipping\FlatRateShipping;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypeInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;

class ShippingMethodTypesTest extends TestCase
{
    public function testEinTypWirdUeberSeinenSchluesselGefunden(): void
    {
        $types = $this->types();

        self::assertInstanceOf(FlatRateShipping::class, $types->get('flat_rate'));
        self::assertSame(['flat_rate'], $types->keys());
        self::assertTrue($types->has('flat_rate'));
        self::assertFalse($types->has('dhl'));
    }

    public function testEinUnbekannterTypWirftStattNullZuLiefern(): void
    {
        // An uninstalled module leaves types with its key behind.
        $this->expectException(UnknownMethodType::class);

        $this->types()->get('dhl');
    }

    public function testRegisterUndSchnittstelleNennenDenselbenTag(): void
    {
        // Two strings, one meaning: if one deviates, the registry is empty.
        $tag = (new \ReflectionClass(ShippingMethodTypeInterface::class))->getAttributes(AutoconfigureTag::class)[0]->getArguments()[0];
        $parameter = (new \ReflectionMethod(ShippingMethodTypes::class, '__construct'))->getParameters()[0];
        $locator = $parameter->getAttributes(AutowireLocator::class)[0]->getArguments();

        self::assertSame('consho.shipping_method_type', $tag);
        self::assertSame($tag, $locator[0]);
        self::assertSame('getType', $locator['defaultIndexMethod']);
    }

    private function types(): ShippingMethodTypes
    {
        return new ShippingMethodTypes(new ServiceLocator(['flat_rate' => static fn (): FlatRateShipping => new FlatRateShipping()]));
    }
}
