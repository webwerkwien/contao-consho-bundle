<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Cart\CartView;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutData;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutState;

/**
 * `withData()` copies every field by hand. Block R added `taxAtDestination`
 * and forgot the copy: the purchase click wrote the order without the switch,
 * and the total check refused it — no test called `withData()` at all.
 *
 * So the state is built here with every field at a value that is NOT its
 * default, and every field but `data` has to come through. A field added to
 * the constructor and not to `withData()` fails here, whatever its type.
 */
class CheckoutStateWithDataTest extends TestCase
{
    public function testJedesFeldAusserDenDatenBleibt(): void
    {
        $constructor = (new \ReflectionClass(CheckoutState::class))->getConstructor();
        self::assertNotNull($constructor);

        $arguments = [];

        foreach ($constructor->getParameters() as $parameter) {
            $arguments[$parameter->getName()] = $this->notDefault($parameter);
        }

        $state = new CheckoutState(...$arguments);
        $copy = $state->withData(new CheckoutData());

        foreach (array_keys($arguments) as $name) {
            if ('data' === $name) {
                continue;
            }

            self::assertEquals($state->{$name}, $copy->{$name}, \sprintf('withData() drops "%s"', $name));
        }

        self::assertNotSame($state->data, $copy->data);
    }

    private function notDefault(\ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();
        $name = $type instanceof \ReflectionNamedType ? $type->getName() : 'mixed';
        $default = $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null;

        return match (true) {
            CartView::class === $name => new CartView([], [], '1', '1.00', '1.20', 'EUR'),
            CheckoutData::class === $name => new CheckoutData(),
            'bool' === $name => true !== $default,
            'int' === $name => 7,
            'string' === $name => 'nicht leer',
            'array' === $name => ['nicht leer'],
            $type instanceof \ReflectionNamedType && enum_exists($name) => $name::cases()[\count($name::cases()) - 1],
            default => null,
        };
    }
}
