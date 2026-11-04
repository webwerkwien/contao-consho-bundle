<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Compiler\AutowirePass;
use Symfony\Component\DependencyInjection\Compiler\ResolveClassPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\MoneyFlowBackendController;
use Webwerkwien\ContaoConshoBundle\Cron\PaymentAttemptCleanupCron;
use Webwerkwien\ContaoConshoBundle\Dashboard\OpenWorkReader;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\MoneyFlowsButtonListener;
use Webwerkwien\ContaoConshoBundle\Order\MoneyFlows;
use Webwerkwien\ContaoConshoBundle\Order\OverpaidOrders;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\ProviderEventBooker;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationProcessor;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\UnassignedRefunds;
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

    /**
     * Without it the cron cancels a paid attempt of a provider whose webhook
     * went astray, and the money sits unbooked (spec Zahlung PayPal, PP2 d).
     */
    public function testDerAufraeumerFragtUeberDenProcessorNach(): void
    {
        self::assertSame(NotificationProcessor::class, $this->argument(PaymentAttemptCleanupCron::class, '$notifications'));
    }

    public function testDerUmstiegAufRechnungLogtImAllgemeinenKanal(): void
    {
        self::assertSame('monolog.logger.contao.general', $this->argument(SubscriptionChargeFallback::class, '$general'));
        self::assertSame('monolog.logger.contao.error', $this->argument(SubscriptionChargeFallback::class, '$logger'));
    }

    /**
     * Without them the list icon stays grey on an overpaid order or a refund
     * that names no payment (block Geldflüsse).
     */
    public function testDasListensymbolKenntUeberzahlungUndRueckzahlungOhneZahlung(): void
    {
        self::assertSame(OverpaidOrders::class, $this->argument(MoneyFlowsButtonListener::class, '$overpaid'));
        self::assertSame(UnassignedRefunds::class, $this->argument(MoneyFlowsButtonListener::class, '$unassigned'));
    }

    public function testDieOffeneArbeitKenntDieUeberzahlung(): void
    {
        self::assertSame(OverpaidOrders::class, $this->argument(OpenWorkReader::class, '$overpaid'));
    }

    /**
     * Without them the page "Geldflüsse" shows neither its movements nor its
     * links.
     */
    public function testDieSeiteGeldfluesseBekommtLeserUndRouter(): void
    {
        self::assertSame(MoneyFlows::class, $this->argument(MoneyFlowBackendController::class, '$flows'));
        self::assertSame(UrlGeneratorInterface::class, $this->argument(MoneyFlowBackendController::class, '$router'));
    }

    private function argument(string $service, string $name): string|null
    {
        $container = new ContainerBuilder();
        (new YamlFileLoader($container, new FileLocator(__DIR__.'/../config')))->load('services.yaml');
        // Provided by Contao's own bundles at runtime.
        $container->register(Connection::class)->setSynthetic(true);
        foreach ([UrlGeneratorInterface::class, Security::class, ContaoFramework::class, RequestStack::class, Environment::class] as $class) {
            $container->register($class)->setSynthetic(true);
        }
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
