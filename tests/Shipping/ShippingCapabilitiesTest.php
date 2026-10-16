<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Shipping;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingCapabilities;

class ShippingCapabilitiesTest extends TestCase
{
    public function testNur016Und18SindAltersstufenEinerUebergabe(): void
    {
        self::assertSame(18, (new ShippingCapabilities(18, true))->ageCheck);

        $this->expectException(\InvalidArgumentException::class);

        new ShippingCapabilities(14, false);
    }
}
