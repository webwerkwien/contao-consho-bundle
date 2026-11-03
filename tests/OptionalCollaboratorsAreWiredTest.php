<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\AutowirePass;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\ProviderEventBooker;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationProcessor;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionChargeFallback;

/**
 * A collaborator typed `X|null $x = null` keeps the unit tests short — and
 * if autowiring cannot resolve it, the container passes the default without
 * a word. For the provider's events that would drop every chargeback with a
 * 200. The tests build NotificationProcessor themselves and never see it.
 *
 * Runs the autowiring of our own services.yaml (not a copy of its rules) and
 * reads what the container would pass. Errors of other services do not
 * matter here: AutowirePass records them on their definitions, and only the
 * argument in question is read.
 */
class OptionalCollaboratorsAreWiredTest extends TestCase
{
    public function testDerProcessorBekommtDenBookerDerProviderEvents(): void
    {
        self::assertSame(ProviderEventBooker::class, $this->argument(NotificationProcessor::class, '$events'));
    }

    public function testDerUmstiegAufRechnungLogtImAllgemeinenKanal(): void
    {
        self::assertSame('monolog.logger.contao.general', $this->argument(SubscriptionChargeFallback::class, '$general'));
        self::assertSame('monolog.logger.contao.error', $this->argument(SubscriptionChargeFallback::class, '$logger'));
    }

    private function argument(string $service, string $name): string|null
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../config')))->load('services.yaml');
        // Provided by Contao's own bundles at runtime.
        $container->register(Connection::class)->setSynthetic(true);
        (new ResolveClassPass())->process($container);
        (new AutowirePass(false))->process($container);

        $definition = $container->getDefinition($service);
        // AutowirePass stops at a definition's first error: the arguments
        // after it would read as missing.
        self::assertSame([], $definition->getErrors());
        $position = null;

        foreach ((new \ReflectionClass($service))->getConstructor()?->getParameters() ?? [] as $index => $parameter) {
            if ('$'.$parameter->getName() === $name) {
                $position = $index;
            }
        }

        self::assertNotNull($position, $name.' is no constructor argument');

        $argument = $definition->getArguments()[$position] ?? $definition->getArguments()[$name] ?? null;

        return $argument instanceof Reference ? (string) $argument : null;
    }
}
