<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Order\OrderLinkOutcome;
use Webwerkwien\ContaoConshoBundle\Order\OrderLinkRequest;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ErrorSpyLogger;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\FakeConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\SpyTransport;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * D-6, T4: "Request a new link". Number AND address, the link only to the
 * order's address, the same answer for a hit and a miss, a limit per IP and
 * per address.
 */
final class OrderLinkRequestTest extends TestCase
{
    private Connection $connection;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    private RateLimiterFactory $perIp;

    private RateLimiterFactory $perAddress;

    /** @var list<array<string, mixed>> */
    private array $linkedOrders = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Same number in two shops: the key is (shop, orderNumber).
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 7, 'shop' => 1, 'language' => 'de', 'orderNumber' => 'WEB-00054', 'email' => 'Kundin@Example.org', 'firstname' => 'Erika', 'lastname' => 'Muster']);
        $this->connection->insert('tl_consho_order', ['id' => 8, 'shop' => 2, 'language' => 'de', 'orderNumber' => 'WEB-00054', 'email' => 'andere@example.org']);

        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();
        $this->perIp = new RateLimiterFactory(['id' => 'ip', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage());
        $this->perAddress = new RateLimiterFactory(['id' => 'mail', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '1 hour'], new InMemoryStorage());
    }

    public function testDerLinkGehtAnDieAdresseDerBestellung(): void
    {
        $outcome = $this->service()->request(1, ' WEB-00054 ', 'kundin@example.org', '10.0.0.1');

        self::assertSame(OrderLinkOutcome::Requested, $outcome);
        self::assertSame([], $this->logger->errors);
        self::assertCount(1, $this->transport->sent);

        $mail = $this->transport->sent[0];
        self::assertSame('Kundin@Example.org', $mail->getTo()[0]->getAddress(), 'die Adresse der Bestellung, nicht die getippte');
        self::assertSame('Ihr Link zur Bestellung WEB-00054', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('https://shop.test/bestellung?consho_order=frisch', $html);
        self::assertStringContainsString('Der Link gilt 90 Tage.', $html);
        self::assertStringContainsString('Erika Muster', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
        self::assertSame([7], array_map(static fn (array $o): int => (int) $o['id'], $this->linkedOrders));
    }

    /**
     * @return iterable<string, array{int, string, string}>
     */
    public static function fehlgriffe(): iterable
    {
        yield 'falsche Adresse' => [1, 'WEB-00054', 'fremd@example.org'];
        yield 'unbekannte Nummer' => [1, 'WEB-99999', 'kundin@example.org'];
        yield 'anderer Shop' => [2, 'WEB-00054', 'kundin@example.org'];
    }

    /**
     * @dataProvider fehlgriffe
     */
    public function testEinFehlgriffAntwortetGleichUndSchicktNichts(int $shop, string $number, string $email): void
    {
        self::assertSame(OrderLinkOutcome::Requested, $this->service()->request($shop, $number, $email, '10.0.0.1'));
        self::assertSame([], $this->transport->sent);
    }

    public function testOhneAngabenNichts(): void
    {
        self::assertSame(OrderLinkOutcome::Missing, $this->service()->request(1, 'WEB-00054', ' ', '10.0.0.1'));
        self::assertSame([], $this->transport->sent);
    }

    public function testFuenfJeIp(): void
    {
        $service = $this->service();

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(OrderLinkOutcome::Requested, $service->request(1, 'WEB-9999'.$i, "k$i@example.org", '10.0.0.1'), 'Fehlgriffe zählen mit');
        }

        self::assertSame(OrderLinkOutcome::TooMany, $service->request(1, 'WEB-00054', 'kundin@example.org', '10.0.0.1'));
        self::assertSame([], $this->transport->sent);
    }

    public function testDreiJeAdresseAuchVonVerschiedenenIps(): void
    {
        $service = $this->service();

        for ($i = 0; $i < 3; ++$i) {
            $service->request(1, 'WEB-00054', 'KUNDIN@example.org', '10.0.0.'.$i);
        }

        self::assertSame(OrderLinkOutcome::TooMany, $service->request(1, 'WEB-00054', 'kundin@example.org', '10.0.0.9'));
        self::assertCount(3, $this->transport->sent);
    }

    /**
     * D-6: even when the IP is already blocked, the address is counted too —
     * otherwise an attacker could read from the limit when his attempts count.
     */
    public function testDieAdresseZaehltAuchBeiGesperrterIp(): void
    {
        $service = $this->service();

        for ($i = 0; $i < 5; ++$i) {
            $service->request(1, 'WEB-9999'.$i, "k$i@example.org", '10.0.0.1');
        }

        self::assertSame(OrderLinkOutcome::TooMany, $service->request(1, 'WEB-00054', 'kundin@example.org', '10.0.0.1'));
        self::assertSame(OrderLinkOutcome::TooMany, $service->request(1, 'WEB-00054', 'kundin@example.org', '10.0.0.1'));
        self::assertSame(OrderLinkOutcome::Requested, $service->request(1, 'WEB-00054', 'kundin@example.org', '10.0.0.2'), 'der dritte Versuch dieser Adresse');
        self::assertSame(OrderLinkOutcome::TooMany, $service->request(1, 'WEB-00054', 'kundin@example.org', '10.0.0.3'), 'die gesperrten Versuche haben mitgezählt');
    }

    public function testEinFehlerBeimVersandVerraetNichts(): void
    {
        $service = $this->service(link: null);

        self::assertSame(OrderLinkOutcome::Requested, $service->request(1, 'WEB-00054', 'kundin@example.org', '10.0.0.1'));
        self::assertCount(1, $this->logger->errors);
        self::assertStringContainsString('WEB-00054', $this->logger->errors[0]);
        self::assertStringNotContainsString('example.org', $this->logger->errors[0], 'ohne Adresse im Log (D-5, L8)');
    }

    private function service(string|null $link = 'https://shop.test/bestellung?consho_order=frisch'): OrderLinkRequest
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');
        $translator = new ContaoLikeTranslator();
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        $factory = new class($this->transport) implements MailTransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function create(array $row): TransportInterface
            {
                return $this->transport;
            }
        };

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturnCallback(function (array $order) use ($link): string|null {
            $this->linkedOrders[] = $order;

            return $link;
        });

        return new OrderLinkRequest($this->connection, $links, new FakeConfirmationSource(), $factory, new MailRenderer($twig, $translator), $this->perIp, $this->perAddress, $this->logger);
    }
}
