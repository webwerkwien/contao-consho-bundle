<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Controller\TestPaymentController;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

class TestPaymentControllerTest extends TestCase
{
    private const SECRET = 'geheim-geheim-geheim';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $sent = [];

    public function testDieSeiteZeigtBestellungBetragUndVierKnoepfeEscaped(): void
    {
        $response = $this->controller()->__invoke(new Request($this->query(['order' => '<b>WEB-1</b>'])));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('&lt;b&gt;WEB-1&lt;/b&gt;', (string) $response->getContent());
        self::assertStringContainsString('13,90', (string) $response->getContent());
        self::assertSame(4, substr_count((string) $response->getContent(), '<button type="submit"'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function testOhneVariableOderMitFalscherUnterschriftGibtEsDieSeiteNicht(): void
    {
        try {
            $this->controller(testPayment: null)->__invoke(new Request($this->query()));
            self::fail('ohne Variable');
        } catch (NotFoundHttpException) {
        }

        $query = $this->query();
        $query['consho_signature'] = str_repeat('0', 64);

        $this->expectException(NotFoundHttpException::class);

        $this->controller()->__invoke(new Request($query));
    }

    public function testBezahlenMeldetUnterschriebenUndKehrtUnterschriebenZurueck(): void
    {
        $response = $this->controller()->__invoke($this->post('pay'));

        self::assertCount(1, $this->sent);
        [$method, $url, $options] = $this->sent[0];
        self::assertSame('POST', $method);
        self::assertSame('https://shop.test/notify/tok', $url);

        $body = (string) $options['body'];
        $data = json_decode($body, true);
        self::assertSame(['attempt' => 3, 'event' => 'paid', 'amount' => '13.9000'], array_intersect_key($data, ['attempt' => 0, 'event' => 0, 'amount' => 0]));
        self::assertContains(TestPaymentHandler::SIGNATURE_HEADER.': '.TestPaymentHandler::sign($body, self::SECRET), $options['headers']);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(
            'https://shop.test/return/tok?consho_result=paid&consho_signature='.TestPaymentHandler::sign(TestPaymentHandler::returnData(3, 'paid'), self::SECRET),
            $response->headers->get('Location'),
        );
    }

    /**
     * Abo-4b, P100: an order with subscription lines gets a mandate with the
     * payment — one without does not.
     */
    public function testMitAboZeilenKommtEinMandatMit(): void
    {
        $this->controller()->__invoke(new Request([], $this->query(['recurring' => 1]) + ['action' => 'pay'], [], [], [], ['REQUEST_METHOD' => 'POST']));
        $this->controller()->__invoke($this->post('pay'));

        self::assertSame('test-mandate-3', json_decode((string) $this->sent[0][2]['body'], true)['mandate'] ?? null);
        self::assertArrayNotHasKey('mandate', json_decode((string) $this->sent[1][2]['body'], true));
    }

    public function testDoppeltSendetZweiMeldungen(): void
    {
        $this->controller()->__invoke($this->post('twice'));

        self::assertCount(2, $this->sent);
        self::assertSame($this->sent[0][2]['body'], $this->sent[1][2]['body'], 'dieselbe Meldung zweimal');
    }

    public function testAbbrechenMeldetNichts(): void
    {
        $response = $this->controller()->__invoke($this->post('cancel'));

        self::assertSame([], $this->sent);
        self::assertStringContainsString('consho_result=cancelled', (string) $response->headers->get('Location'));
    }

    public function testKommtDieMeldungNichtMit200ZurueckBleibtDieSeiteStehen(): void
    {
        $response = $this->controller(status: 500)->__invoke($this->post('pay'));

        self::assertSame(502, $response->getStatusCode());
        self::assertFalse($response->headers->has('Location'));
        self::assertStringContainsString('role="alert"', (string) $response->getContent());
        self::assertStringContainsString('500', (string) $response->getContent());
    }

    /**
     * @param array<string, int|string> $overrides
     *
     * @return array<string, string>
     */
    private function query(array $overrides = []): array
    {
        $session = TestPaymentHandler::encode($overrides + [
            'attempt' => 3,
            'order' => 'WEB-00001',
            'amount' => '13.9000',
            'currency' => 'EUR',
            'notify' => 'https://shop.test/notify/tok',
            'return' => 'https://shop.test/return/tok',
        ]);

        return ['consho_method' => '7', 'consho_session' => $session, 'consho_signature' => TestPaymentHandler::sign($session, self::SECRET)];
    }

    private function post(string $action): Request
    {
        return new Request([], $this->query() + ['action' => $action], [], [], [], ['REQUEST_METHOD' => 'POST']);
    }

    private function controller(string|null $testPayment = '1', int $status = 200): TestPaymentController
    {
        $sent = &$this->sent;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$sent, $status): MockResponse {
            $sent[] = [$method, $url, $options];

            return new MockResponse('', ['http_code' => $status]);
        });

        $methods = $this->createMock(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturnCallback(static fn (int $id): PaymentMethod|null => 7 === $id ? new PaymentMethod(7, 'test', 'Test', ['testSecret' => self::SECRET]) : null);

        $handler = new TestPaymentHandler($this->createMock(UrlGeneratorInterface::class));

        return new TestPaymentController(
            new PaymentHandlers(new ServiceLocator(['test' => static fn () => $handler]), $testPayment),
            $methods,
            $client,
            new MoneyFormatter(),
        );
    }
}
