<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Address;

use Contao\CoreBundle\Routing\ScopeMatcher;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Yaml\Yaml;
use Webwerkwien\ContaoConshoBundle\Address\RateLimitedVatIdValidator;
use Webwerkwien\ContaoConshoBundle\Address\VatIdCheckResult;
use Webwerkwien\ContaoConshoBundle\Address\VatIdStatus;
use Webwerkwien\ContaoConshoBundle\Address\VatIdValidatorInterface;
use Webwerkwien\ContaoConshoBundle\Address\ViesVatIdValidator;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\CheckoutController;
use Webwerkwien\ContaoConshoBundle\Customer\MemberVatId;

final class RateLimitedVatIdValidatorTest extends TestCase
{
    private int $calls = 0;

    private RequestStack $requests;

    private RateLimitedVatIdValidator $validator;

    protected function setUp(): void
    {
        $inner = new class($this->calls) implements VatIdValidatorInterface {
            public function __construct(private int &$calls)
            {
            }

            public function check(string $vatId, string|null $requesterVatId = null): VatIdCheckResult
            {
                ++$this->calls;

                return new VatIdCheckResult(VatIdStatus::Valid, 1000, 'Musterfirma GmbH', 'WAPI1');
            }
        };

        $limiter = new RateLimiterFactory(
            ['id' => 'test', 'policy' => 'fixed_window', 'limit' => 2, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );

        $this->requests = new RequestStack();

        // A request is the back end where it carries this attribute.
        $backend = new class implements RequestMatcherInterface {
            public function matches(Request $request): bool
            {
                return 'backend' === $request->attributes->get('_scope');
            }
        };

        $this->validator = new RateLimitedVatIdValidator(
            $inner,
            $limiter,
            $this->requests,
            new ScopeMatcher($backend, $this->createStub(RequestMatcherInterface::class), $this->requests),
        );
    }

    /**
     * An editor saving many members in the back end: each save may check the
     * member's VAT ID, and none of them counts.
     */
    public function testImBackendWirdNichtGezaehlt(): void
    {
        $request = Request::create('/contao', server: ['REMOTE_ADDR' => '192.0.2.1']);
        $request->attributes->set('_scope', 'backend');
        $this->requests->push($request);

        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(VatIdStatus::Valid, $this->validator->check('DE123456789')->status);
        }

        $this->assertSame(5, $this->calls);
    }

    /**
     * Read from config/services.yaml: the member's VAT ID — registration,
     * "Persönliche Daten", the checkout — goes through the same limit.
     */
    public function testDieUidDesMitgliedsGehtDurchDieselbeGrenze(): void
    {
        /** @var array{services: array<string, mixed>} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml');

        $this->assertSame('@'.RateLimitedVatIdValidator::class, $config['services'][MemberVatId::class]['arguments']['$validator']);
    }

    public function testUeberDerGrenzeGiltDiePruefungAlsNichtErreichbarOhneVies(): void
    {
        $this->requests->push(Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']));

        $this->assertSame(VatIdStatus::Valid, $this->validator->check('DE123456789', 'ATU12345678')->status);
        $this->assertSame(VatIdStatus::Valid, $this->validator->check('DE123456789', 'ATU12345678')->status);

        $refused = $this->validator->check('DE123456789', 'ATU12345678');

        $this->assertSame(VatIdStatus::Unavailable, $refused->status);
        $this->assertNull($refused->requestIdentifier);
        $this->assertSame(2, $this->calls);
    }

    public function testDieGrenzeGiltJeIp(): void
    {
        $this->requests->push(Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.1']));
        $this->validator->check('DE123456789');
        $this->validator->check('DE123456789');

        $this->requests->push(Request::create('/', server: ['REMOTE_ADDR' => '192.0.2.2']));

        $this->assertSame(VatIdStatus::Valid, $this->validator->check('DE123456789')->status);
        $this->assertSame(3, $this->calls);
    }

    /**
     * Read from config/services.yaml: the checkout gets the limited check,
     * through the wrapper around the real VIES client; everything else asks
     * VIES directly.
     */
    public function testDerCheckoutBekommtDieBegrenztePruefung(): void
    {
        /** @var array{services: array<string, mixed>} $config */
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/services.yaml');
        $services = $config['services'];

        $this->assertSame('@'.RateLimitedVatIdValidator::class, $services[CheckoutController::class]['arguments']['$vatIds']);
        $this->assertSame('@'.ViesVatIdValidator::class, $services[RateLimitedVatIdValidator::class]['arguments']['$inner']);
        $this->assertSame('@consho.rate_limit.vat_check', $services[RateLimitedVatIdValidator::class]['arguments']['$limiter']);
        $this->assertSame(ViesVatIdValidator::class, $services[VatIdValidatorInterface::class]['alias']);
        $this->assertSame(
            ['id' => 'consho_vat_check', 'policy' => 'fixed_window', 'limit' => 10, 'interval' => '15 minutes'],
            $services['consho.rate_limit.vat_check']['arguments'][0],
        );
    }

    public function testOhneAnfrageWirdNichtGezaehlt(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->assertSame(VatIdStatus::Valid, $this->validator->check('DE123456789')->status);
        }

        $this->assertSame(5, $this->calls);
    }
}
