<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Payment;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentReturn;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;
use Webwerkwien\ContaoConshoBundle\Token\TokenRepositoryInterface;

/**
 * Die Rückkehr bucht nie Geld (Abschnitt 12) und vermerkt nur, was der
 * Handler belegt hat (Michael, 2026-09-26).
 */
class PaymentReturnTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SECRET = 'geheim-geheim-geheim';

    private InMemoryPaymentAttempts $attempts;

    /** @var list<array{int, TokenPurpose, bool}> */
    private array $issued = [];

    protected function setUp(): void
    {
        $this->attempts = new InMemoryPaymentAttempts();
        $this->attempts->add(new PaymentAttempt(3, 1, 7, 'test', AttemptState::Open, '13.9000', 'EUR', '', '', ''), self::TOKEN);
    }

    public function testEinBelegterErfolgWirdVermerktUndBuchtNichts(): void
    {
        $url = $this->returns()->handle(self::TOKEN, $this->request('paid'), 100);

        self::assertMatchesRegularExpression('~^https://example\.org/bestellung\.html\?consho_order=[0-9a-f]{64}$~', (string) $url);
        self::assertSame(AttemptState::Open, $this->attempts->attempts[3]->state);
        self::assertSame(AttemptState::Succeeded, $this->attempts->attempts[3]->returned);
        self::assertSame([], $this->attempts->payments);

        // Ein frisches Anzeige-Token, das die Adresse NICHT bestätigt.
        self::assertSame([[1, TokenPurpose::ViewOrder, false]], $this->issued);
    }

    public function testEinBelegterAbbruchSetztDenVersuch(): void
    {
        $this->returns()->handle(self::TOKEN, $this->request('cancelled'), 100);

        self::assertSame(AttemptState::Cancelled, $this->attempts->attempts[3]->state);
        self::assertSame(1, $this->attempts->locks);
    }

    public function testEineVonHandGetippteRueckkehrVermerktNichts(): void
    {
        $url = $this->returns()->handle(self::TOKEN, new Request(['consho_result' => 'paid', 'consho_signature' => 'falsch']), 100);

        self::assertNotNull($url, 'die Bestellseite bekommt der Käufer trotzdem');
        self::assertNull($this->attempts->attempts[3]->returned);
        self::assertSame(AttemptState::Open, $this->attempts->attempts[3]->state);
        self::assertSame(0, $this->attempts->locks);
    }

    public function testUnbekannterVersuchIstNull(): void
    {
        self::assertNull($this->returns()->handle(str_repeat('b', 64), $this->request('paid'), 100));
        self::assertSame([], $this->issued);
    }

    private function request(string $result): Request
    {
        return new Request(['consho_result' => $result, 'consho_signature' => TestPaymentHandler::sign(TestPaymentHandler::returnData(3, $result), self::SECRET)]);
    }

    private function returns(): PaymentReturn
    {
        $methods = $this->createMock(PaymentMethodLookupInterface::class);
        $methods->method('byId')->willReturn(new PaymentMethod(7, 'test', 'Test', ['testSecret' => self::SECRET]));

        $handler = new TestPaymentHandler($this->createMock(UrlGeneratorInterface::class));

        $orders = $this->createMock(OrderConfirmationSourceInterface::class);
        $orders->method('order')->willReturnCallback(static fn (int $id): array|null => 1 === $id ? ['id' => 1, 'shop' => 1, 'language' => 'de'] : null);
        $orders->method('rootOf')->willReturn(1);

        $issued = &$this->issued;
        $tokens = $this->createMock(TokenRepositoryInterface::class);
        $tokens->method('store')->willReturnCallback(static function (int $orderId, TokenPurpose $purpose, string $hash, int $now, int|null $expires, bool $confirms = false) use (&$issued): void {
            $issued[] = [$orderId, $purpose, $confirms];
        });

        $root = $this->createMock(PageModel::class);
        $root->method('row')->willReturn(['consho_orderPage' => 245]);
        $orderPage = $this->createMock(PageModel::class);

        $pages = $this->createMock(Adapter::class);
        $pages->method('__call')->willReturnCallback(
            static fn (string $name, array $args): PageModel|null => 'findById' === $name ? [1 => $root, 245 => $orderPage][$args[0]] ?? null : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($pages);

        $urls = $this->createMock(ContentUrlGenerator::class);
        $urls->method('generate')->willReturn('https://example.org/bestellung.html');

        return new PaymentReturn(
            DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]),
            $this->attempts,
            $methods,
            new PaymentHandlers(new ServiceLocator(['test' => static fn () => $handler]), '1'),
            $orders,
            new TokenGenerator($tokens),
            new ShopPageUrls($framework, new ShopResolver($framework), $urls),
            $framework,
        );
    }
}
