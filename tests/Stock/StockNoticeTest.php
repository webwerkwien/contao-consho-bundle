<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Stock;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;
use Webwerkwien\ContaoConshoBundle\Cron\StockNoticeCron;
use Webwerkwien\ContaoConshoBundle\Customer\GuestOrderConfirmation;
use Webwerkwien\ContaoConshoBundle\Customer\MemberOrders;
use Webwerkwien\ContaoConshoBundle\Mail\MailTransportFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Notification\MailRenderer;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockNotices;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;
use Webwerkwien\ContaoConshoBundle\Tests\Confirmation\FakeOptIn;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ErrorSpyLogger;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\FakeConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\SpyTransport;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;

require_once __DIR__.'/../Confirmation/EmailConfirmationTest.php';
require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * Block G, G-6 (G12, G15): "Notify me when back in stock" — members only,
 * exactly one mail to the current account address, and its link confirms
 * the address by the rules of GuestOrderConfirmation.
 *
 * Article 1: physical, stock managed, sold out.
 */
class StockNoticeTest extends TestCase
{
    private Connection $connection;

    private FakeOptIn $optIn;

    private SpyTransport $transport;

    private ErrorSpyLogger $logger;

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_stock', 'tl_consho_stock_movement', 'tl_consho_stock_notice', 'tl_consho_product_visibility'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, email TEXT NOT NULL DEFAULT '', disable INTEGER NOT NULL DEFAULT 0, consho_confirmedEmail TEXT NOT NULL DEFAULT '', consho_confirmedEmailAt INTEGER NULL, tstamp INTEGER NOT NULL DEFAULT 0)");
        $this->connection->insert('tl_member', ['id' => 5, 'email' => 'kundin@example.org']);
        $this->connection->insert('tl_member', ['id' => 6, 'email' => 'weg@example.org', 'disable' => 1]);

        // Visible in shop 1 in German (K5: only then a wish).
        $this->connection->insert('tl_consho_product_visibility', ['pid' => 1, 'main' => 1, 'shop' => 1, 'language' => 'de', 'currency' => 'EUR']);
        $this->connection->insert('tl_consho_product', ['id' => 1, 'title' => 'Wanderstock &amp; Hülle', 'stockTracked' => 1]);
        (new StockRows($this->connection))->sync(1, 100);

        $this->optIn = new FakeOptIn();
        $this->transport = new SpyTransport();
        $this->logger = new ErrorSpyLogger();
    }

    public function testEinWunschNurSolangeNichtsDaIst(): void
    {
        self::assertTrue($this->notices()->subscribe(1, 5, 1, 9, 'de', 1000));
        self::assertTrue($this->notices()->subscribe(1, 5, 1, 9, 'de', 1001), 'zweimal ist einmal');
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'));
        self::assertFalse($this->notices()->subscribe(1, 0, 1, 9, 'de', 1000), 'nur Mitglieder');

        $this->notices()->unsubscribe(1, 5);
        self::assertFalse($this->notices()->isSubscribed(1, 5));

        $this->stock('2');
        self::assertFalse($this->notices()->subscribe(1, 5, 1, 9, 'de', 1002), 'lieferbar: nichts zu wünschen');
    }

    /**
     * Nachlese K5 (G-7): A wish only for an article the start point
     * shows — another shop or another language: nothing stored.
     */
    public function testNurFuerEinenArtikelDesStartpunkts(): void
    {
        self::assertFalse($this->notices()->subscribe(1, 5, 2, 9, 'de', 1000), 'anderer Shop');
        self::assertFalse($this->notices()->subscribe(1, 5, 1, 9, 'fr', 1000), 'andere Sprache');
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'));
    }

    public function testDerCronSchreibtErstWennWiederWasDaIst(): void
    {
        $this->notices()->subscribe(1, 5, 1, 9, 'de', 1000);

        $this->cron()();
        self::assertSame([], $this->transport->sent);

        $this->stock('3');
        $this->connection->update('tl_member', ['email' => 'neu@example.org'], ['id' => 5]);
        $this->cron()();

        self::assertCount(1, $this->transport->sent);
        $mail = $this->transport->sent[0];
        self::assertSame('neu@example.org', $mail->getTo()[0]->getAddress(), 'die Adresse beim Versand, nicht beim Wunsch');
        self::assertStringContainsString('Wanderstock & Hülle', (string) $mail->getSubject(), 'dekodiert, nicht doppelt kodiert');
        self::assertStringContainsString('https://shop.test/produkt/wanderstock.html?consho_confirm=cosn-', (string) $mail->getHtmlBody());

        self::assertSame('cosn', $this->optIn->tokens[0]->prefix);
        self::assertSame('neu@example.org', $this->optIn->tokens[0]->getEmail());
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'), 'genau eine Mail');

        $this->cron()();
        self::assertCount(1, $this->transport->sent());
    }

    public function testScheitertDieMailBleibtDerWunsch(): void
    {
        $this->notices()->subscribe(1, 5, 1, 9, 'de', 1000);
        $this->stock('3');
        $this->transport->fail = true;

        $this->cron()();

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'));
        self::assertStringNotContainsString('kundin@example.org', implode(' ', $this->logger->errors), 'ohne Adresse im Log');
        self::assertNotSame([], $this->logger->errors);
    }

    /**
     * G: An invalid account address is in the exception's message —
     * it still does not get into the log.
     */
    public function testEineUngueltigeAdresseKommtNichtInsLog(): void
    {
        $this->connection->update('tl_member', ['email' => 'keineadresse'], ['id' => 5]);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 1, 'main' => 1, 'member' => 5, 'shop' => 1, 'root' => 9, 'language' => 'de']);
        $this->stock('3');

        $this->cron()();

        self::assertNotSame([], $this->logger->errors);
        self::assertStringNotContainsString('keineadresse', implode(' ', $this->logger->errors), 'Symfony nennt sie in der Meldung der Ausnahme');
    }

    /**
     * G: a wish without an article or without a product page goes —
     * not an error every hour.
     */
    public function testUnerreichbareWuenscheGehen(): void
    {
        $this->connection->insert('tl_consho_stock_notice', ['article' => 99, 'main' => 99, 'member' => 5, 'shop' => 1, 'root' => 9, 'language' => 'de']);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 1, 'main' => 1, 'member' => 5, 'shop' => 1, 'root' => 404, 'language' => 'de']);
        $this->stock('3');

        $this->cron()();
        $this->cron()();

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'));
        self::assertCount(1, $this->logger->errors, 'einmal gemeldet');
    }

    /**
     * G: Another error while building the address does not stop the cron
     * for everyone — the wish stays, the others go out.
     */
    public function testEinFehlerInDerAdresseHaeltDieAnderenNichtAuf(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 98, 'title' => 'Kaputt', 'stockTracked' => 1]);
        (new StockRows($this->connection))->sync(98, 100);
        $this->connection->update('tl_consho_stock', ['quantity' => '3.0000'], ['article' => 98]);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 98, 'main' => 98, 'member' => 5, 'shop' => 1, 'root' => 9, 'language' => 'de']);
        $this->notices()->subscribe(1, 5, 1, 9, 'de', 1000);
        $this->stock('3');

        $this->cron()();

        self::assertCount(1, $this->transport->sent);
        self::assertSame([98], array_map('intval', $this->connection->fetchFirstColumn('SELECT article FROM tl_consho_stock_notice')));
        self::assertStringNotContainsString('router refused', implode(' ', $this->logger->errors));
    }

    public function testDeaktivierteMitgliederBekommenNichts(): void
    {
        $this->connection->insert('tl_consho_stock_notice', ['article' => 1, 'main' => 1, 'member' => 6, 'shop' => 1, 'root' => 9, 'language' => 'de']);
        $this->connection->insert('tl_consho_stock_notice', ['article' => 1, 'main' => 1, 'member' => 77, 'shop' => 1, 'root' => 9, 'language' => 'de']);
        $this->stock('3');

        $this->cron()();

        self::assertSame([], $this->transport->sent);
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_stock_notice'));
    }

    /**
     * G15: The link confirms the address — by the same four rules as for
     * guest orders, with a token prefix of its own.
     */
    public function testDerLinkBestaetigtDieAdresseAngemeldetAlsDiesesKonto(): void
    {
        $this->notices()->subscribe(1, 5, 1, 9, 'de', 1000);
        $this->stock('3');
        $this->cron()();
        $token = $this->optIn->tokens[0]->getIdentifier();

        $confirmations = new GuestOrderConfirmation($this->confirmation(), new MemberOrders($this->connection));

        self::assertSame('login', $confirmations->state($token, 0, '', ConfirmationPurpose::StockNotice));
        self::assertSame('otherAccount', $confirmations->state($token, 6, 'kundin@example.org', ConfirmationPurpose::StockNotice));
        self::assertSame('invalid', $confirmations->state($token, 5, 'kundin@example.org'), 'kein Token der Gastbestellungen');
        self::assertSame('pending', $confirmations->state($token, 5, 'kundin@example.org', ConfirmationPurpose::StockNotice));

        self::assertSame('confirmed', $confirmations->confirm($token, 5, 'kundin@example.org', 2000, ConfirmationPurpose::StockNotice));
        self::assertSame('kundin@example.org', $this->connection->fetchOne('SELECT consho_confirmedEmail FROM tl_member WHERE id = 5'));
    }

    private function stock(string $quantity): void
    {
        $this->connection->update('tl_consho_stock', ['quantity' => $quantity], ['article' => 1]);
    }

    private function notices(): StockNotices
    {
        return new StockNotices($this->connection, new Availability($this->connection));
    }

    private function confirmation(): EmailConfirmation
    {
        $twig = new Environment(new FilesystemLoader());

        return new EmailConfirmation($this->optIn, new FakeConfirmationSource(), $this->factory(), new MailRenderer($twig, new ContaoLikeTranslator()), $this->logger);
    }

    private function cron(): StockNoticeCron
    {
        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../contao/templates', 'Contao');

        $translator = new ContaoLikeTranslator();
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addExtension(new TranslationExtension($translator));

        return new StockNoticeCron(
            $this->connection,
            new Availability($this->connection),
            new FakeConfirmationSource(),
            $this->factory(),
            new MailRenderer($twig, $translator),
            $this->urls(),
            $this->confirmation(),
            $this->logger,
        );
    }

    private function factory(): MailTransportFactoryInterface
    {
        return new class($this->transport) implements MailTransportFactoryInterface {
            public function __construct(private readonly TransportInterface $transport)
            {
            }

            public function create(array $row): TransportInterface
            {
                return $this->transport;
            }
        };
    }

    private function urls(): ProductUrlGenerator
    {
        $contentUrls = $this->createMock(ContentUrlGenerator::class);
        $contentUrls->method('generate')->willReturnCallback(
            static fn (object $content, array $values, int $referenceType): string => 'https://shop.test/produkt/'.($values['alias'] ?? '').'.html',
        );

        $productPages = $this->createMock(ProductPageRepositoryInterface::class);
        $productPages->method('findForRootPage')->willReturnCallback(static fn (int $root): int|null => 404 === $root ? null : 123);
        $productPages->method('urlValues')->willReturnCallback(static fn (int $id): array => 98 === $id ? throw new \RuntimeException('router refused') : ['alias' => 'wanderstock']);

        $productPage = $this->createMock(PageModel::class);
        $productPage->method('row')->willReturn(['conshoPathTemplate' => '{alias}']);

        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->willReturnCallback(static fn (string $name, array $args): PageModel|null => 123 === ($args[0] ?? null) ? $productPage : null);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($adapter);

        return new ProductUrlGenerator($contentUrls, $productPages, $framework, new ShopResolver($framework), new ShopLanguages($this->connection));
    }
}
