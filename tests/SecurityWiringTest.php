<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;
use Symfony\Component\Yaml\Yaml;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Cart\CodeAttemptLimit;
use Webwerkwien\ContaoConshoBundle\Controller\AddToCartController;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\CancellationController;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\WithdrawalController;
use Webwerkwien\ContaoConshoBundle\Customer\AccountFromOrder;
use Webwerkwien\ContaoConshoBundle\Customer\MyOrdersFlow;
use Webwerkwien\ContaoConshoBundle\Order\OrderLinkRequest;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;

/**
 * The limits, the signer and the test payment switch as the container builds
 * them: read from config/services.yaml and the constructors' #[Autowire].
 *
 * The unit tests build these services by hand, with limiters of their own. A
 * limiter argument with a default of null is skipped by autowiring without a
 * word, and a changed value in the configuration shows in no other test —
 * only here does it show that the limit is really on, and how high.
 */
final class SecurityWiringTest extends TestCase
{
    /**
     * @var array<string, mixed>
     */
    private array $services;

    protected function setUp(): void
    {
        /** @var array{services: array<string, mixed>} $config */
        $config = Yaml::parseFile(\dirname(__DIR__).'/config/services.yaml');
        $this->services = $config['services'];
    }

    /**
     * @return iterable<string, array{class-string, string, string, int, string}>
     */
    public static function limits(): iterable
    {
        yield 'withdrawal form' => [WithdrawalController::class, 'limiter', 'consho.rate_limit.withdrawal_form', 5, '15 minutes'];
        yield 'unassigned withdrawal per shop' => [WithdrawalRecorder::class, 'perShop', 'consho.rate_limit.withdrawal_unassigned_shop', 20, '1 hour'];
        yield 'unassigned withdrawal per address' => [WithdrawalRecorder::class, 'perAddress', 'consho.rate_limit.withdrawal_unassigned_address', 3, '1 day'];
        yield 'cancellation form' => [CancellationController::class, 'limiter', 'consho.rate_limit.cancellation_form', 5, '15 minutes'];
        yield 'e-mail confirmation' => [MyOrdersFlow::class, 'limiter', 'consho.rate_limit.email_confirmation', 3, '1 hour'];
        yield 'order link per IP' => [OrderLinkRequest::class, 'perIp', 'consho.rate_limit.order_link_ip', 5, '15 minutes'];
        yield 'order link per address' => [OrderLinkRequest::class, 'perAddress', 'consho.rate_limit.order_link_address', 3, '1 hour'];
        yield 'account per IP' => [AccountFromOrder::class, 'perIp', 'consho.rate_limit.account_ip', 5, '15 minutes'];
        yield 'account per address' => [AccountFromOrder::class, 'perAddress', 'consho.rate_limit.account_address', 3, '1 hour'];
        yield 'codes in the basket' => [CodeAttemptLimit::class, 'limiter', 'consho.rate_limit.code_entry', 10, '15 minutes'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('limits')]
    public function testDieGrenzeIstVerdrahtetUndHatIhrenWert(string $class, string $parameter, string $service, int $limit, string $interval): void
    {
        $this->assertSame($service, $this->autowired($class, $parameter));
        $this->assertLimiter($service, $limit, $interval);
    }

    public function testNeueGastwarenkoerbeSindJeIpBegrenzt(): void
    {
        $this->assertSame('@consho.rate_limit.new_basket', $this->services[AddToCartController::class]['arguments']['$newBaskets']);
        $this->assertLimiter('consho.rate_limit.new_basket', 60, '15 minutes');
    }

    public function testAlleGrenzenTeilenDenRateLimiterCache(): void
    {
        $storage = $this->services['consho.rate_limit.withdrawal_form_storage'];

        $this->assertSame(CacheStorage::class, $storage['class']);
        $this->assertSame(['@cache.rate_limiter'], $storage['arguments']);
    }

    /**
     * The result page of a cancellation is opened by its signed address
     * (§ 312k Abs. 3 BGB); the key is the kernel secret, the parameters are
     * the form's own.
     */
    public function testDieKuendigungsseiteWirdMitDemKernelSecretSigniert(): void
    {
        $signer = $this->services['consho.cancellation_signer'];

        $this->assertSame(UriSigner::class, $signer['class']);
        $this->assertSame(['%kernel.secret%', CancellationForm::HASH_PARAM, CancellationForm::EXPIRES_PARAM], $signer['arguments']);
        $this->assertSame('consho.cancellation_signer', $this->autowired(CancellationForm::class, 'signer'));
    }

    /**
     * Unset, the variable resolves to null and the test provider is off
     * (PaymentHandlersTest has the values).
     */
    public function testDieTestzahlungHaengtNurAnDerVariable(): void
    {
        $attribute = $this->parameter(PaymentHandlers::class, 'testPayment')->getAttributes(Autowire::class)[0]->newInstance();

        $this->assertSame('%env(default::CONSHO_TEST_PAYMENT)%', $attribute->value);
        $this->assertTrue($this->parameter(PaymentHandlers::class, 'testPayment')->isDefaultValueAvailable());
        $this->assertNull($this->parameter(PaymentHandlers::class, 'testPayment')->getDefaultValue());
    }

    /**
     * @param class-string $class
     */
    private function autowired(string $class, string $parameter): string
    {
        $attributes = $this->parameter($class, $parameter)->getAttributes(Autowire::class);

        $this->assertCount(1, $attributes, $class.'::$'.$parameter);

        return (string) $attributes[0]->newInstance()->value;
    }

    /**
     * @param class-string $class
     */
    private function parameter(string $class, string $name): \ReflectionParameter
    {
        foreach ((new \ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
            if ($name === $parameter->getName()) {
                return $parameter;
            }
        }

        $this->fail($class.' has no constructor parameter $'.$name);
    }

    private function assertLimiter(string $service, int $limit, string $interval): void
    {
        $definition = $this->services[$service];

        $this->assertSame(RateLimiterFactory::class, $definition['class'], $service);
        $this->assertSame($limit, $definition['arguments'][0]['limit'], $service);
        $this->assertSame($interval, $definition['arguments'][0]['interval'], $service);
        $this->assertSame('fixed_window', $definition['arguments'][0]['policy'], $service);
        $this->assertSame('@consho.rate_limit.withdrawal_form_storage', $definition['arguments'][1], $service);
    }
}
