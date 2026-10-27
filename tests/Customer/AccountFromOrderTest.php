<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Customer\AccountFromOrder;
use Webwerkwien\ContaoConshoBundle\Customer\AccountPasswordPage;
use Webwerkwien\ContaoConshoBundle\Customer\AccountRequestOutcome;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Tests\Confirmation\FakeOptIn;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ErrorSpyLogger;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\FakeConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\SpyTransport;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';
require_once __DIR__.'/../Confirmation/EmailConfirmationTest.php';

/**
 * K1: "Kundenkonto anlegen" on the order page of a guest order. The account
 * starts closed and without a password; the mail carries Contao's own
 * `pw-` token to the "Lost password" page. An address that already has an
 * account gets a mail saying so, and the page answers the same.
 */
final class AccountFromOrderTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    private FakeOptIn $optIn;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    private RateLimiterFactory $perIp;

    private RateLimiterFactory $perAddress;

    private string|null $passwordPage = 'https://shop.test/passwort';

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, dateAdded INTEGER NOT NULL DEFAULT 0,
            firstname TEXT NOT NULL DEFAULT '', lastname TEXT NOT NULL DEFAULT '', company TEXT NOT NULL DEFAULT '', street TEXT NOT NULL DEFAULT '', postal TEXT NOT NULL DEFAULT '',
            city TEXT NOT NULL DEFAULT '', state TEXT NOT NULL DEFAULT '', country TEXT NOT NULL DEFAULT '', phone TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '',
            language TEXT NOT NULL DEFAULT '', groups BLOB NULL, login INTEGER NOT NULL DEFAULT 0, username TEXT NULL, password TEXT NOT NULL DEFAULT '', disable INTEGER NOT NULL DEFAULT 0,
            consho_shippingCompany TEXT NOT NULL DEFAULT '', consho_shippingFirstname TEXT NOT NULL DEFAULT '', consho_shippingLastname TEXT NOT NULL DEFAULT '',
            consho_shippingStreet TEXT NOT NULL DEFAULT '', consho_shippingPostal TEXT NOT NULL DEFAULT '', consho_shippingCity TEXT NOT NULL DEFAULT '',
            consho_shippingState TEXT NOT NULL DEFAULT '', consho_shippingCountry TEXT NOT NULL DEFAULT '', consho_vatId TEXT NOT NULL DEFAULT '',
            consho_accountPending INTEGER NOT NULL DEFAULT 0)");
        // As in Contao: the username is unique.
        $this->connection->executeStatement('CREATE UNIQUE INDEX username ON tl_member (username)');

        // Start point 1 (FakeConfirmationSource::rootOf()) with its "Lost password" page.
        DcaSchema::createCore($this->connection, 'tl_page');
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Shop', 'published' => 1, 'consho_passwordPage' => 30]);
        $this->connection->insert('tl_page', ['id' => 30, 'pid' => 1, 'type' => 'regular', 'title' => 'Passwort', 'published' => 1]);

        $this->optIn = new FakeOptIn();
        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();
        $this->perIp = new RateLimiterFactory(['id' => 'ip', 'policy' => 'fixed_window', 'limit' => 5, 'interval' => '15 minutes'], new InMemoryStorage());
        $this->perAddress = new RateLimiterFactory(['id' => 'mail', 'policy' => 'fixed_window', 'limit' => 3, 'interval' => '1 hour'], new InMemoryStorage());
    }

    public function testEinNeuesKontoStartetGesperrtMitDenDatenDerBestellung(): void
    {
        self::assertSame(AccountRequestOutcome::Requested, $this->service()->request($this->order(), '10.0.0.1', self::NOW));
        self::assertSame([], $this->logger->errors);

        $member = $this->connection->fetchAssociative('SELECT * FROM tl_member');
        self::assertIsArray($member);
        self::assertSame('Kundin@Example.org', $member['email']);
        self::assertSame('Kundin@Example.org', $member['username'], 'Benutzername = Adresse (K1-4)');
        self::assertSame(1, (int) $member['login']);
        self::assertSame(1, (int) $member['disable'], 'gesperrt bis zum Passwort (K1-6)');
        self::assertSame('', $member['password']);
        self::assertSame(self::NOW, (int) $member['consho_accountPending']);
        self::assertNull($member['groups'], 'die Gruppe erst beim Freischalten');
        self::assertSame(
            ['Erika', 'Muster', 'Muster GmbH', 'Hauptstraße 1', '1100', 'Wien', 'AT', '+43 1 234', 'de', 'ATU12345678'],
            [$member['firstname'], $member['lastname'], $member['company'], $member['street'], $member['postal'], $member['city'], $member['country'], $member['phone'], $member['language'], $member['consho_vatId']],
        );
        self::assertSame('', $member['consho_shippingStreet'], 'keine eigene Lieferadresse in der Bestellung');

        self::assertCount(1, $this->optIn->tokens);
        $token = $this->optIn->tokens[0];
        self::assertSame('pw', $token->prefix, 'Contaos eigenes Token für „Passwort vergessen"');
        self::assertSame('Kundin@Example.org', $token->getEmail(), 'Contao vergleicht es mit tl_member.email');
        self::assertSame(['tl_member' => [(int) $member['id']]], $token->getRelatedRecords());

        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertSame('Kundin@Example.org', $mail->getTo()[0]->getAddress());
        self::assertSame('Ihr Kundenkonto: Passwort festlegen', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('https://shop.test/passwort?token='.$token->getIdentifier(), $html);
        self::assertStringContainsString('Kundin@Example.org', $html, 'nennt den Benutzernamen');
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    public function testEineEigeneLieferadresseKommtMit(): void
    {
        $this->service()->request($this->order([
            'shippingCompany' => '', 'shippingFirstname' => 'Max', 'shippingLastname' => 'Muster', 'shippingStreet' => 'Nebengasse 2',
            'shippingPostal' => '1010', 'shippingCity' => 'Wien', 'shippingState' => '', 'shippingCountry' => 'AT',
        ]), '10.0.0.1', self::NOW);

        self::assertSame(
            ['Max', 'Muster', 'Nebengasse 2', '1010', 'Wien', 'AT'],
            array_values($this->connection->fetchAssociative('SELECT consho_shippingFirstname, consho_shippingLastname, consho_shippingStreet, consho_shippingPostal, consho_shippingCity, consho_shippingCountry FROM tl_member') ?: []),
        );
    }

    public function testEinBestehendesKontoBekommtNurEinenHinweis(): void
    {
        $this->connection->insert('tl_member', ['id' => 9, 'email' => 'kundin@example.org', 'username' => 'erika', 'login' => 1]);

        self::assertSame(AccountRequestOutcome::Requested, $this->service()->request($this->order(), '10.0.0.1', self::NOW), 'dieselbe Antwort (K1-5)');

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_member'), 'kein zweites Konto');
        self::assertSame([], $this->optIn->tokens, 'kein Token für ein fremdes Passwort');
        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertSame('Kundin@Example.org', $mail->getTo()[0]->getAddress());
        self::assertSame('Sie haben bereits ein Kundenkonto', $mail->getSubject());
        $html = (string) $mail->getHtmlBody();
        self::assertStringContainsString('erika', $html, 'nennt das Konto');
        self::assertStringContainsString('https://shop.test/passwort', $html);
        self::assertStringNotContainsString('token=', $html);
    }

    public function testEinWartendesKontoBekommtEinenNeuenLink(): void
    {
        $this->service()->request($this->order(), '10.0.0.1', self::NOW);
        $this->service()->request($this->order(), '10.0.0.1', self::NOW + 60);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_member'));
        self::assertCount(2, $this->optIn->tokens);
        self::assertCount(2, $this->transport->sent);
        self::assertSame('Ihr Kundenkonto: Passwort festlegen', $this->transport->sent[1]->getSubject());
        self::assertSame(self::NOW, (int) $this->connection->fetchOne('SELECT consho_accountPending FROM tl_member'), 'die Frist läuft ab dem ersten Antrag');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string|null}>
     */
    public static function ohneAngebot(): iterable
    {
        yield 'Bestellung eines Kontos' => [['member' => 5], 'https://shop.test/passwort'];
        yield 'anonymisiert' => [['email' => ''], 'https://shop.test/passwort'];
        yield 'Adresse zu lang für den Benutzernamen' => [['email' => str_repeat('a', 60).'@example.org'], 'https://shop.test/passwort'];
        yield 'keine Seite „Passwort vergessen"' => [[], null];
    }

    /**
     * @param array<string, mixed> $override
     *
     * @dataProvider ohneAngebot
     */
    public function testOhneAngebotKeinKasten(array $override, string|null $page): void
    {
        $this->passwordPage = $page;
        $order = $this->order($override);

        self::assertFalse($this->service()->offered($order));
        self::assertSame(AccountRequestOutcome::Unavailable, $this->service()->request($order, '10.0.0.1', self::NOW));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_member'));
        self::assertSame([], $this->transport->sent);
    }

    public function testEineGastbestellungMitSeiteBekommtDenKasten(): void
    {
        self::assertTrue($this->service()->offered($this->order()));
    }

    /**
     * The page the link leads to must be usable, and below the order's own
     * start point: the activation takes the group from there.
     */
    public function testEineUnbrauchbareSeiteZaehltNicht(): void
    {
        $this->connection->update('tl_page', ['protected' => 1], ['id' => 30]);
        self::assertFalse($this->service()->offered($this->order()), 'geschützt');

        $this->connection->update('tl_page', ['protected' => 0, 'pid' => 2], ['id' => 30]);
        $this->connection->insert('tl_page', ['id' => 2, 'type' => 'root', 'title' => 'Anderer Shop', 'published' => 1]);
        self::assertFalse($this->service()->offered($this->order()), 'unter einem anderen Startpunkt');

        $this->connection->update('tl_page', ['pid' => 1], ['id' => 30]);
        self::assertTrue($this->service()->offered($this->order()));
    }

    public function testEinFremderBenutzernameVerhindertDasKontoOhneMail(): void
    {
        $this->connection->insert('tl_member', ['id' => 9, 'email' => 'owner@example.org', 'username' => 'Kundin@Example.org', 'login' => 1]);

        self::assertSame(AccountRequestOutcome::Requested, $this->service()->request($this->order(), '10.0.0.1', self::NOW), 'dieselbe Antwort');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_member'));
        self::assertSame([], $this->transport->sent, 'nichts über ein fremdes Konto an diese Adresse');
        self::assertSame([], $this->optIn->tokens);
    }

    public function testDieAdresseGehtVorDemBenutzernamen(): void
    {
        $this->connection->insert('tl_member', ['id' => 8, 'email' => 'owner@example.org', 'username' => 'Kundin@Example.org', 'login' => 1]);
        $this->connection->insert('tl_member', ['id' => 9, 'email' => 'KUNDIN@example.org', 'username' => 'erika', 'login' => 1]);

        $this->service()->request($this->order(), '10.0.0.1', self::NOW);

        self::assertCount(1, $this->transport->sent);
        self::assertSame('Sie haben bereits ein Kundenkonto', $this->transport->sent[0]->getSubject());
        self::assertStringContainsString('erika', (string) $this->transport->sent[0]->getHtmlBody());
    }

    /**
     * Only the account of a parallel request is swallowed (a race, MySQL
     * only); any other unique violation is an error and says so.
     */
    public function testEineFremdeEindeutigkeitVerletzungBleibtEinFehler(): void
    {
        $this->connection->executeStatement('CREATE UNIQUE INDEX phone ON tl_member (phone)');
        $this->connection->insert('tl_member', ['id' => 9, 'email' => 'andere@example.org', 'username' => 'andere', 'phone' => '+43 1 234']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->service()->request($this->order(), '10.0.0.1', self::NOW);
    }

    public function testDieFelderPassenInDieSpaltenDesMitglieds(): void
    {
        $this->service()->request($this->order(['billingState' => str_repeat('x', 100), 'phone' => str_repeat('1', 80), 'billingCountry' => 'at']), '10.0.0.1', self::NOW);

        $member = $this->connection->fetchAssociative('SELECT state, phone, country FROM tl_member');
        self::assertIsArray($member);
        self::assertSame(64, mb_strlen((string) $member['state']));
        self::assertSame(64, mb_strlen((string) $member['phone']));
        self::assertSame('AT', $member['country']);
    }

    public function testDreiJeAdresse(): void
    {
        $service = $this->service();

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            self::assertSame(AccountRequestOutcome::Requested, $service->request($this->order(), $ip, self::NOW));
        }

        self::assertSame(AccountRequestOutcome::TooMany, $service->request($this->order(), '10.0.0.4', self::NOW));
        self::assertCount(3, $this->transport->sent);
    }

    public function testFuenfJeIp(): void
    {
        $service = $this->service();

        for ($i = 1; $i <= 5; ++$i) {
            self::assertSame(AccountRequestOutcome::Requested, $service->request($this->order(['email' => "k{$i}@example.org"]), '10.0.0.1', self::NOW));
        }

        self::assertSame(AccountRequestOutcome::TooMany, $service->request($this->order(['email' => 'k6@example.org']), '10.0.0.1', self::NOW));
    }

    public function testEinFehlerBeimVersandNenntDieAdresseNicht(): void
    {
        $this->transport->fail = true;

        self::assertSame(AccountRequestOutcome::Requested, $this->service()->request($this->order(), '10.0.0.1', self::NOW));
        self::assertCount(1, $this->logger->errors);
        self::assertStringContainsString('WEB-00054', $this->logger->errors[0]);
        self::assertStringNotContainsString('Kundin', $this->logger->errors[0]);
    }

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, string>
     */
    private function order(array $override = []): array
    {
        $order = $override + [
            'id' => 7, 'shop' => 1, 'member' => 0, 'language' => 'de', 'orderNumber' => 'WEB-00054',
            'email' => 'Kundin@Example.org', 'company' => 'Muster GmbH', 'salutation' => '', 'firstname' => 'Erika', 'lastname' => 'Muster',
            'phone' => '+43 1 234', 'billingStreet' => 'Hauptstraße 1', 'billingPostal' => '1100', 'billingCity' => 'Wien', 'billingState' => '',
            'billingCountry' => 'AT', 'vatId' => 'ATU12345678',
            'shippingCompany' => '', 'shippingFirstname' => '', 'shippingLastname' => '', 'shippingStreet' => '', 'shippingPostal' => '',
            'shippingCity' => '', 'shippingState' => '', 'shippingCountry' => '',
        ];

        // As DbalOrderPageRepository hands it over: every value a string.
        return array_map(static fn ($v): string => (string) $v, $order);
    }

    private function service(): AccountFromOrder
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

        $links = $this->createMock(OrderPageLink::class);
        $links->method('passwordPage')->willReturnCallback(fn (): string|null => $this->passwordPage);

        return new AccountFromOrder($this->connection, $this->optIn, $links, new AccountPasswordPage($this->connection), new FakeConfirmationSource(), $factory, new MailRenderer($twig, $translator), $this->perIp, $this->perAddress, $this->logger);
    }
}
