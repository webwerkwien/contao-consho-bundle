<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPdfInterface;
use Webwerkwien\ContaoConshoBundle\Privacy\CustomerErasure;
use Webwerkwien\ContaoConshoBundle\Privacy\CustomerExport;
use Webwerkwien\ContaoConshoBundle\Privacy\OrderAnonymizer;
use Webwerkwien\ContaoConshoBundle\Privacy\RetentionPolicy;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Privacy\PrivacySchema;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Twig\TwigFunction;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\CustomerBackendController;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerDirectory;

/**
 * D-4, K2/K6 — die Seite „Kunden": Liste mit Filter und Suche, dazu ein
 * Kunde mit seinen Bestellungen. Mit der echten Vorlage und Sprachdatei.
 */
final class CustomerBackendControllerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        // The shared D-5 schema: production column names (Fall 31).
        $this->connection = PrivacySchema::create();
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien']);
        $this->connection->insert('tl_member', ['id' => 5, 'firstname' => 'Erika', 'lastname' => 'Konto', 'email' => 'erika@example.org']);
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shop' => 1, 'member' => 5, 'orderNumber' => 'WEB-1', 'email' => 'erika@example.org', 'createdAt' => strtotime('2026-09-20 10:00'), 'grandTotal' => '22.8000']);
        $this->connection->insert('tl_consho_order', ['id' => 2, 'shop' => 1, 'member' => 0, 'orderNumber' => 'WEB-2', 'email' => 'erika@example.org', 'createdAt' => strtotime('2026-09-21 10:00'), 'grandTotal' => '10.0000']);
        $this->connection->insert('tl_consho_order', ['id' => 3, 'shop' => 1, 'member' => 0, 'orderNumber' => 'WEB-3', 'email' => 'gast@example.org', 'firstname' => 'Max <b>', 'lastname' => 'Gast', 'createdAt' => strtotime('2026-09-22 10:00')]);

        $GLOBALS['TL_LANG'] = [];
        $GLOBALS['TL_LANGUAGE'] = 'de';
        require __DIR__.'/../../../contao/languages/de/default.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_LANGUAGE']);
    }

    public function testDieListe(): void
    {
        $html = $this->controller('/contao?do=consho_customer')();

        self::assertStringContainsString('<option value="1">Wien</option>', $html);
        self::assertStringContainsString('Max &lt;b&gt; Gast', $html, 'Kundeneingabe maskiert');
        self::assertStringContainsString('Erika Konto', $html);
        self::assertStringContainsString('Mitglied <span class="tl_gray">+ Gast</span>', $html);
        // Die Anzahl steht wie bei Contao in „Anzeigen" (Abnahme D-5).
        self::assertStringContainsString('<label for="consho_customer_page">Anzeigen</label>', $html);
        self::assertStringContainsString('<option value="1" selected>1 - 2</option>', $html);
        self::assertStringContainsString('/contao?do=consho_customer&amp;shop=1&amp;customer=m%3A5', $html);
        // Das Mitgliedskonto im Popup: sein „Zurück" führte zu „Mitglieder".
        self::assertStringContainsString("Backend.openModalIframe({title:'Mitgliedskonto\\u0020\\u00F6ffnen', url:this.href+'&amp;popup=1&amp;nb=1'})", $html);

        // Wie die Bestellliste (Michael): Operationen rechts, Filter hinter „Filtern".
        self::assertStringContainsString('class="tl_file_list tl_right_nowrap"', $html);
        self::assertStringContainsString('<img src="archive.svg"', $html);
        self::assertStringContainsString('<img src="member.svg"', $html);
        self::assertStringContainsString('<img src="member--disabled.svg" alt="Gast — kein Mitgliedskonto">', $html, 'der Gast hat kein Konto');
        self::assertStringContainsString('class="header_filter_toggle"', $html);
        self::assertStringContainsString('id="tl_content_filter"', $html);
        self::assertStringNotContainsString('CONSHO.', $html);
    }

    public function testSucheUndUnbekannterShop(): void
    {
        $html = $this->controller('/contao?do=consho_customer&q=gast@&shop=99')();

        self::assertStringContainsString('>1 - 1</option>', $html);
        self::assertStringNotContainsString('Erika', $html);
        self::assertStringContainsString('value="gast@"', $html);
    }

    public function testEinKundeMitSeinenBestellungen(): void
    {
        $html = $this->controller('/contao?do=consho_customer&shop=1&customer=m:5')();

        self::assertStringContainsString('Erika Konto', $html);
        self::assertStringContainsString('auch als Gast bestellt', $html);
        self::assertStringContainsString('/contao?do=member&amp;act=edit&amp;id=5', $html);
        self::assertStringContainsString('>WEB-2</a>', $html);
        self::assertStringContainsString('>WEB-1</a>', $html);
        self::assertLessThan(strpos($html, '>WEB-1<'), strpos($html, '>WEB-2<'), 'neueste zuerst');
        self::assertStringContainsString('22,80', $html);
        self::assertStringContainsString('/contao?do=consho_order&amp;act=edit&amp;id=2', $html);
        self::assertStringContainsString("title:'WEB\\u002D2'", $html, 'die Bestellung im Popup');
    }

    public function testEinUnbekannterKundeZeigtDieListe(): void
    {
        $html = $this->controller('/contao?do=consho_customer&shop=1&customer=m:77')();

        self::assertStringContainsString('>1 - 2</option>', $html);
    }

    public function testMehrereSeiten(): void
    {
        for ($i = 10; $i < 62; ++$i) {
            $this->connection->insert('tl_consho_order', ['id' => $i, 'shop' => 1, 'member' => 0, 'orderNumber' => 'WEB-'.$i, 'email' => "k$i@example.org", 'createdAt' => strtotime('2026-09-23 10:00')]);
        }

        $html = $this->controller('/contao?do=consho_customer&page=2')();

        self::assertStringContainsString('<option value="1">1 - 50</option>', $html);
        self::assertStringContainsString('<option value="2" selected>51 - 54</option>', $html);
    }

    public function testKeineKundenKeineAuswahl(): void
    {
        $html = $this->controller('/contao?do=consho_customer&q=niemand')();

        self::assertStringContainsString('Keine Kunden gefunden.', $html);
        self::assertStringNotContainsString('consho_customer_page', $html);
    }

    /** D-5, L1: die Auskunft kommt als ZIP, nie aus einem Cache. */
    public function testDieAuskunftKommtAlsZip(): void
    {
        $html = $this->controller('/contao?do=consho_customer&shop=1&customer=m:5')();
        self::assertStringContainsString('/contao?do=consho_customer&amp;shop=1&amp;customer=m%3A5&amp;export=1', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);

        try {
            $this->controller('/contao?do=consho_customer&shop=1&customer=m:5&export=1')();
            self::fail('Die Auskunft muss als Datei kommen.');
        } catch (ResponseException $e) {
            $response = $e->getResponse();
            self::assertSame('application/zip', $response->headers->get('Content-Type'));
            self::assertStringContainsString('attachment; filename=auskunft-', (string) $response->headers->get('Content-Disposition'));
            self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
            self::assertStringStartsWith("PK\x03\x04", (string) $response->getContent());
        }
    }

    /** D-5, L3: ohne Häkchen nichts; mit Häkchen gelöscht bzw. vermerkt. */
    public function testDieLoeschungBrauchtDasHaekchen(): void
    {
        $this->connection->update('tl_consho_shop', ['retentionYears' => 7], ['id' => 1]);

        self::assertSame('/contao?do=consho_customer&shop=1&customer=m%3A5', $this->redirectOf('/contao?do=consho_customer&shop=1&customer=m:5', ['consho_action' => 'erase']));
        self::assertSame([['addError', 'Bitte bestätigen Sie zuerst, dass die Löschung verlangt und die Identität geprüft ist. Es wurde nichts gelöscht.']], $this->messages);
        self::assertNull($this->connection->fetchOne('SELECT erasureRequestedAt FROM tl_consho_order WHERE id = 1'));

        $this->messages = [];
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-2-1', 'member' => 5, 'firstOrder' => 1, 'state' => 'active']);
        $this->redirectOf('/contao?do=consho_customer&shop=1&customer=m:5', ['consho_action' => 'erase', 'consho_confirmed' => '1']);

        self::assertNotNull($this->connection->fetchOne('SELECT erasureRequestedAt FROM tl_consho_order WHERE id = 1'), 'in der Frist: vermerkt');
        self::assertSame('erika@example.org', $this->connection->fetchOne('SELECT email FROM tl_consho_order WHERE id = 1'), 'in der Frist: unverändert');
        /** @var list<array{0: string, 1: string}> $messages filled through the adapter's reference */
        $messages = $this->messages;
        self::assertSame('addInfo', $messages[0][0] ?? null);
        self::assertStringContainsString('WEB-2 (bis', $messages[0][1]);
        self::assertContains(['addInfo', 'Laufende Abos, werden nach Vertragsende anonymisiert: WEB-2-1.'], $messages, 'Abo-DSGVO, P87');
    }

    /** D-5 (Fable-Review S4): Löschen verlangt das Bearbeitungsrecht an den Bestellungen. */
    public function testOhneBearbeitungsrechtKeineLoeschung(): void
    {
        $this->granted = false;

        try {
            $this->controller('/contao?do=consho_customer&shop=1&customer=m:5', ['consho_action' => 'erase', 'consho_confirmed' => '1'])();
            self::fail('Ohne Recht muss die Löschung abgelehnt werden.');
        } catch (AccessDeniedHttpException) {
        }

        self::assertNull($this->connection->fetchOne('SELECT erasureRequestedAt FROM tl_consho_order WHERE id = 1'));
    }

    private bool $granted = true;

    /** @var list<array{0: string, 1: string}> */
    private array $messages = [];

    /**
     * @param array<string, string> $post
     */
    private function redirectOf(string $uri, array $post): string
    {
        try {
            $this->controller($uri, $post)();
        } catch (RedirectResponseException $e) {
            return (string) $e->getResponse()->headers->get('Location');
        }

        self::fail('Es wurde nicht umgeleitet.');
    }

    /**
     * @param array<string, string>|null $post
     */
    private function controller(string $uri, array|null $post = null): CustomerBackendController
    {
        $requests = new RequestStack();
        $requests->push(null === $post ? Request::create($uri) : Request::create($uri, 'POST', $post));

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);
        // Contao's backend_icon, reduced to what the test reads.
        $twig->addFunction(new TwigFunction('backend_icon', static fn (string $src, string $alt = ''): string => \sprintf('<img src="%s" alt="%s">', $src, htmlspecialchars($alt)), ['is_safe' => ['html']]));

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $params): string => '/contao?'.http_build_query($params));

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            if ('generate' !== $name) {
                $messages[] = [$name, (string) $args[0]];
            }

            return '';
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($message);

        $directory = new CustomerDirectory($this->connection);
        $policy = new RetentionPolicy($this->connection);
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($this->granted);
        $pdfs = new class implements DocumentPdfInterface {
            /**
             * @return array{pdf: string, fileName: string, fromArchive: bool, archiveProblem: bool, logoMissing: bool}
             */
            public function pdf(int $documentId): array
            {
                return ['pdf' => '%PDF', 'fileName' => $documentId.'.pdf', 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
            }
        };

        return new CustomerBackendController(
            $directory,
            $requests,
            $twig,
            $router,
            $framework,
            new CustomerExport($this->connection, $directory, $pdfs),
            new CustomerErasure($this->connection, $directory, $policy, new OrderAnonymizer($this->connection, $this->createStub(VirtualFilesystemInterface::class))),
            $this->connection,
            $security,
        );
    }
}
