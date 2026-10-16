<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;
use Webwerkwien\ContaoConshoBundle\Customer\GuestOrderConfirmation;
use Webwerkwien\ContaoConshoBundle\Customer\MemberOrders;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Tests\Confirmation\FakeOptIn;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ErrorSpyLogger;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\FakeConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\SpyTransport;

require_once __DIR__.'/../Confirmation/EmailConfirmationTest.php';

/**
 * D-4, K3 — die Regeln hinter „Bestellungen als Gast anzeigen". Der Angriff,
 * gegen den sie stehen: Ein Konto trägt unter „Persönliche Daten" eine fremde
 * Adresse ein und fordert die Bestätigung an. Die Mail geht an die
 * rechtmäßige Inhaberin — ein Klick von ihr darf dem Konto nichts freigeben.
 */
final class GuestOrderConfirmationTest extends TestCase
{
    private Connection $connection;

    private FakeOptIn $optIn;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, email TEXT NOT NULL DEFAULT '', consho_confirmedEmail TEXT NOT NULL DEFAULT '', consho_confirmedEmailAt INTEGER NULL)");
        $this->connection->insert('tl_member', ['id' => 5, 'email' => 'erika@example.org']);
        $this->connection->insert('tl_member', ['id' => 6, 'email' => 'angreifer@example.org']);
        $this->optIn = new FakeOptIn();
    }

    public function testDerRegulaereWeg(): void
    {
        $service = $this->service();
        self::assertTrue($service->request(1, 5, 'erika@example.org', 'de', 'https://shop.test/konto'));
        $token = $this->optIn->tokens[0]->getIdentifier();

        self::assertSame('pending', $service->state($token, 5, 'Erika@Example.org'), 'Groß-/Kleinschreibung egal');
        self::assertFalse($this->orders()->guestOrdersConfirmed(5), 'Öffnen allein bestätigt nichts');

        self::assertSame('confirmed', $service->confirm($token, 5, 'erika@example.org', 1000));
        self::assertTrue($this->orders()->guestOrdersConfirmed(5));
        self::assertSame('confirmed', $service->state($token, 5, 'erika@example.org'));
        self::assertSame('confirmed', $service->confirm($token, 5, 'erika@example.org', 1001), 'zweiter Klick ist kein Fehler');
    }

    /**
     * Der Angriff: Konto 6 hat Erikas Adresse eingetragen und die Mail
     * angefordert. Erika klickt — in ihrem eigenen Browser, nicht als Konto 6.
     */
    public function testDieEmpfaengerinKannFuerEinFremdesKontoNichtsBestaetigen(): void
    {
        $this->connection->update('tl_member', ['email' => 'erika@example.org'], ['id' => 6]);
        $service = $this->service();
        $service->request(1, 6, 'erika@example.org', 'de', 'https://shop.test/konto');
        $token = $this->optIn->tokens[0]->getIdentifier();

        self::assertSame('login', $service->state($token, 0, ''), 'nicht angemeldet');
        self::assertSame('login', $service->confirm($token, 0, '', 1000));

        // Angemeldet in ihrem EIGENEN Konto 5 mit derselben Adresse.
        self::assertSame('otherAccount', $service->state($token, 5, 'erika@example.org'));
        self::assertSame('otherAccount', $service->confirm($token, 5, 'erika@example.org', 1000));

        self::assertFalse($this->optIn->tokens[0]->isConfirmed());
        self::assertFalse($this->orders()->guestOrdersConfirmed(6));
        self::assertFalse($this->orders()->guestOrdersConfirmed(5));
    }

    public function testEineZwischendurchGeaenderteAdresseGiltNicht(): void
    {
        $service = $this->service();
        $service->request(1, 5, 'erika@example.org', 'de', 'https://shop.test/konto');
        $token = $this->optIn->tokens[0]->getIdentifier();

        self::assertSame('otherAccount', $service->confirm($token, 5, 'neu@example.org', 1000));
        self::assertFalse($this->optIn->tokens[0]->isConfirmed());
    }

    public function testUngueltigerToken(): void
    {
        self::assertSame('invalid', $this->service()->state('cogo-nix', 5, 'erika@example.org'));
        self::assertSame('invalid', $this->service()->confirm('cogo-nix', 5, 'erika@example.org', 1000));
    }

    public function testOhneKontoOderAdresseKeineMail(): void
    {
        self::assertFalse($this->service()->request(1, 0, 'erika@example.org', 'de', 'https://shop.test/'));
        self::assertFalse($this->service()->request(1, 5, ' ', 'de', 'https://shop.test/'));
        self::assertSame([], $this->optIn->tokens);
    }

    private function orders(): MemberOrders
    {
        return new MemberOrders($this->connection);
    }

    private function service(): GuestOrderConfirmation
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');
        $translator = new ContaoLikeTranslator();
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        $factory = new class(new SpyTransport()) implements MailTransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function create(array $row): TransportInterface
            {
                return $this->transport;
            }
        };

        return new GuestOrderConfirmation(
            new EmailConfirmation($this->optIn, new FakeConfirmationSource(), $factory, new MailRenderer($twig, $translator), new ErrorSpyLogger()),
            $this->orders(),
        );
    }
}
