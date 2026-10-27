<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Exception\ResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContext;
use Contao\CoreBundle\Routing\ResponseContext\HtmlHeadBag\HtmlHeadBag;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\String\HtmlAttributes;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\OrderController;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;
use Webwerkwien\ContaoConshoBundle\Customer\AccountFromOrder;
use Webwerkwien\ContaoConshoBundle\Customer\AccountRequestOutcome;
use Webwerkwien\ContaoConshoBundle\Document\DocumentDeliveryInterface;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoicingPolicy;
use Webwerkwien\ContaoConshoBundle\Document\DocumentPayloadBuilder;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceView;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Notification\PaymentChangeMail;
use Webwerkwien\ContaoConshoBundle\Order\DbalOrderPageRepository;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\OrderLinkOutcome;
use Webwerkwien\ContaoConshoBundle\Order\OrderLinkRequest;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChange;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeBlock;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChanged;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeOptions;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRefused;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Payment\AttemptState;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentAttempt;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStart;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentStarter;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\InMemoryPaymentAttempts;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\FakeConfirmationSource;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Token\TokenValidator;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Withdrawal\DbalWithdrawalRepository;
use Webwerkwien\ContaoConshoBundle\Withdrawal\OrderPageWithdrawal;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalWindowReader;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Webwerkwien\ContaoConshoBundle\Digital\DeliveryHandlers;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalFiles;
use Webwerkwien\ContaoConshoBundle\Digital\KeyPoolHandler;
use Webwerkwien\ContaoConshoBundle\Digital\OrderPageDownloads;
use Webwerkwien\ContaoConshoBundle\Document\ArchiveFolderProtector;
use Webwerkwien\ContaoConshoBundle\Tests\Digital\FakePresentableHandler;

require_once __DIR__.'/../../Notification/OrderConfirmationTest.php';

/**
 * The order page (7.7, C21, C-10).
 */
class OrderControllerTest extends TestCase
{
    private const SHOP = 1;
    private const ROOT_PAGE = 5;
    private const ORDER_PAGE = 9;
    private const ORDER = 7;
    private const URL = 'https://shop.example/bestellung.html';

    private Connection $connection;

    private string $token;

    private PaymentChange&MockObject $change;

    private PaymentChangeMail&MockObject $mail;

    private PaymentStarter&MockObject $payments;

    private OrderLinkRequest&MockObject $linkRequest;

    private InMemoryPaymentAttempts $attempts;

    // Block GARAN: null — no guarantee, as before.
    private DurabilityLabelImages|null $durability = null;

    // Block Lizenz (LZ-1): null — no entitlements, as before.
    private OrderPageDownloads|null $downloads = null;

    private FakePresentableHandler|null $module = null;

    // K1: null — no account box, as before.
    private AccountFromOrder|null $account = null;

    // Block R: null — Consho mails the invoices, as before.
    private InvoicingPolicy|null $policy = null;

    private ResponseContext $context;

    /** Whether the token in the address came by mail (C-10). */
    private bool $tokenFromMail = false;

    /** @var array{number: string, from: string, to: string}|null Abo-4: the invoice snapshot's subscription */
    private array|null $invoiceSubscription = null;

    /** @var (\Closure(FakeConfirmationSource): void)|null EZ-4: adjusts the source before the page reads it */
    private \Closure|null $sourceSetup = null;

    /** @var list<string> hashes markUsed() was called with */
    private array $used = [];

    /** @var list<bool> confirmsEmail of every token the controller issued */
    private array $issued = [];

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection(); // the withdrawal locks its order (K2)
        DcaSchema::create($this->connection, 'tl_consho_shop');
        DcaSchema::create($this->connection, 'tl_consho_order');
        // From the DCA (WG-7), not a copy: a hand-written schema goes stale with the next column.
        DcaSchema::create($this->connection, 'tl_consho_payment');
        DcaSchema::create($this->connection, 'tl_consho_order_item');
        DcaSchema::create($this->connection, 'tl_consho_delivery');
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_delivery_item');
        DcaSchema::create($this->connection, 'tl_consho_document');

        $this->connection->insert('tl_consho_shop', ['id' => self::SHOP, 'fallbackLanguage' => 'de', 'operatorAddress' => 'office@shop.example']);
        // Currency and language as OrderFactory writes them; the DCA defaults
        // are empty (the hand-written table defaulted to EUR/de).
        $this->connection->insert('tl_consho_order', ['id' => self::ORDER, 'shop' => self::SHOP, 'orderNumber' => 'WEB-00054', 'grandTotal' => '13.90', 'paymentMethod' => 3, 'email' => 'kundin@example.org', 'firstname' => 'Erika', 'lastname' => 'Muster', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => self::ORDER, 'productName' => 'Wanderstock', 'lineType' => 'product', 'quantity' => 1]);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => self::ORDER, 'lineType' => 'shipping', 'quantity' => 1]);
        $this->connection->insert('tl_consho_document', ['id' => 14, 'pid' => self::ORDER, 'type' => 'invoice', 'documentNumber' => 'RE-00014', 'issuedAt' => 1_758_700_000]);
        $this->connection->insert('tl_consho_document', ['id' => 99, 'pid' => 8, 'type' => 'invoice', 'documentNumber' => 'RE-00099', 'issuedAt' => 1_758_700_000]);
        $this->connection->insert('tl_consho_document', ['id' => 15, 'pid' => self::ORDER, 'type' => 'creditNote', 'documentNumber' => 'RK-00001', 'issuedAt' => 1_758_800_000, 'relatedDocument' => 14]);

        $this->token = str_repeat('ab', 32);
        $this->change = $this->createMock(PaymentChange::class);
        $this->change->method('options')->willReturn(new PaymentChangeOptions(null));
        $this->mail = $this->createMock(PaymentChangeMail::class);
        $this->payments = $this->createMock(PaymentStarter::class);
        $this->linkRequest = $this->createMock(OrderLinkRequest::class);
        $this->attempts = new InMemoryPaymentAttempts();
        $this->context = new ResponseContext();
        $this->context->add(new HtmlHeadBag());
    }

    public function testDieSeiteZeigtDieBestellungUndBleibtPrivat(): void
    {
        [$data, $response] = $this->render();

        self::assertSame('WEB-00054', $data['order']['orderNumber']);
        self::assertSame('open', $data['payment_state']);

        // Only the goods count: no delivery ever ships shipping and payment
        // lines, they would keep the state at "partial" forever.
        self::assertSame('open', $data['delivery_state']);

        // D-3: the invoice correction stands next to the invoice.
        self::assertSame(['RE-00014', 'RK-00001'], array_column($data['documents'], 'number'));
        self::assertTrue($response->headers->getCacheControlDirective('private'));
        self::assertTrue($response->headers->getCacheControlDirective('no-store'));
        self::assertSame('no-referrer', $this->context->getHeaderBag()->get('Referrer-Policy'));
        self::assertSame('noindex, nofollow', $this->context->getHeaderBag()->get('X-Robots-Tag'));

        // On a real installation the web server overwrites the header. The
        // value in the document applies anyway — exactly once, even if one was
        // there already.
        $head = $this->context->get(HtmlHeadBag::class);
        self::assertSame('noindex,nofollow', $head->getMetaRobots());

        $referrer = array_values(array_filter($head->getMetaTags(), static fn ($tag): bool => 'referrer' === ($tag['name'] ?? null)));
        self::assertCount(1, $referrer);
        self::assertSame('no-referrer', $referrer[0]['content'] ?? null);
    }

    /**
     * WG-7: whoever pays a part with a voucher sees the payment and what is
     * open on the order page — before, only "teilweise bezahlt" (partly
     * paid). Without a payment none of that (the payment notice says it).
     */
    /**
     * Block GARAN (GA6): the guarantees as they are frozen on the lines —
     * from the rows of the order.
     */
    public function testDieSeiteZeigtDieEingefrorenenGarantien(): void
    {
        $this->durability = $this->createMock(DurabilityLabelImages::class);
        $this->durability->expects(self::once())->method('ofOrderItems')
            ->with(self::callback(static fn (array $items): bool => 'Wanderstock' === ($items[0]['productName'] ?? null)))
            ->willReturn([['name' => 'Wanderstock', 'years' => 5, 'src' => 'files/garan.png', 'width' => null, 'height' => null]]);

        [$data] = $this->render();

        self::assertSame([['name' => 'Wanderstock', 'years' => 5, 'src' => 'files/garan.png', 'width' => null, 'height' => null]], $data['garan']);
    }

    public function testDieSeiteZeigtZahlungenUndDenOffenenBetrag(): void
    {
        [$data] = $this->render();
        self::assertSame([], $data['payments']);
        self::assertSame('', $data['outstanding']);

        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '10.00', 'method' => 'Gutschein …ZZ3P', 'reference' => 'voucher:2']);
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '-2.00', 'method' => 'refund']);
        // WG-6: a chargeback onto the voucher carries its name, but is a refund.
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '-1.00', 'method' => 'Gutschein …ZZ3P', 'reference' => 'voucher:2']);

        [$data] = $this->render();

        self::assertSame(['Gutschein …ZZ3P', '', 'Gutschein …ZZ3P'], array_column($data['payments'], 'label'));
        self::assertSame([false, true, true], array_column($data['payments'], 'refund'));
        // 13.90 − 10 + 2 + 1 = 6.90 open
        self::assertSame('6,90 €', str_replace("\u{a0}", ' ', $data['outstanding']));
    }

    /**
     * EZ-4: the page states the tax single-purpose vouchers already carried
     * and the remaining tax as the invoice does — without an invoice yet from
     * the payments, with one from its snapshot. The order's tax is 2.32.
     */
    public function testDieSeiteZeigtDieVerbleibendeSteuerNachEinemEinzweckGutschein(): void
    {
        $this->connection->update('tl_consho_order', ['taxTotal' => '2.32'], ['id' => self::ORDER]);
        $voucher = ['label' => 'Gutschein …ZZ3P', 'country' => 'AT', 'rate' => '20.0000', 'net' => '9.3800', 'tax' => '1.8700', 'gross' => '11.2500'];

        $this->sourceSetup = static function (FakeConfirmationSource $source) use ($voucher): void {
            $source->invoiceRow = null;
            $source->prepaidRows = [$voucher];
        };
        [$data] = $this->render();

        self::assertSame(['Gutschein …ZZ3P'], array_column($data['order']['prepaid'], 'label'));
        self::assertSame('0,45 €', str_replace("\u{a0}", ' ', $data['order']['taxDue']));

        // With an invoice its snapshot counts, not today's payments.
        $this->sourceSetup = static function (FakeConfirmationSource $source) use ($voucher): void {
            if (null !== $source->invoiceRow) {
                $source->invoiceRow['payload']['prepaid'] = [['tax' => '1.0000'] + $voucher];
            }
            $source->prepaidRows = [$voucher];
        };
        [$data] = $this->render();

        self::assertSame('1,32 €', str_replace("\u{a0}", ' ', $data['order']['taxDue']));
    }

    public function testEineVorhandeneReferrerAngabeWirdErsetztNichtVerdoppelt(): void
    {
        $this->context->get(HtmlHeadBag::class)->addMetaTag(new HtmlAttributes(['name' => 'referrer', 'content' => 'unsafe-url']));

        $this->render();

        $referrer = array_values(array_filter($this->context->get(HtmlHeadBag::class)->getMetaTags(), static fn ($tag): bool => 'referrer' === ($tag['name'] ?? null)));
        self::assertCount(1, $referrer);
        self::assertSame('no-referrer', $referrer[0]['content'] ?? null);
    }

    public function testIstDieWareRausIstDieBestellungVersandt(): void
    {
        // Only the product line is delivered — the shipping line never.
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => self::ORDER, 'reportedAt' => 1_790_000_000]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => 1]);

        self::assertSame('complete', $this->render()[0]['delivery_state']);
    }

    public function testEineNochNichtGemeldeteLieferungIstNichtRaus(): void
    {
        // D-1: the operator is still filling the parcel — only the report counts.
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => self::ORDER]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => 1]);

        self::assertSame('open', $this->render()[0]['delivery_state']);
    }

    public function testEinAufrufBestaetigtDieAdresseNicht(): void
    {
        $this->render();

        self::assertNull($this->connection->fetchOne('SELECT emailConfirmedAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));
    }

    public function testDerErsteKlickBestaetigtDieAdresseUndNurDer(): void
    {
        $this->tokenFromMail = true;

        $this->post(['consho_order_action' => 'pdf', 'document' => '14']);
        $first = $this->connection->fetchOne('SELECT emailConfirmedAt FROM tl_consho_order WHERE id = ?', [self::ORDER]);

        self::assertNotNull($first);

        $this->connection->update('tl_consho_order', ['emailConfirmedAt' => 1], ['id' => self::ORDER]);
        $this->post(['consho_order_action' => 'html', 'document' => '14']);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT emailConfirmedAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));
    }

    /**
     * C-10: the link from the mail, opened for the first time — a button in
     * front of the order whose POST confirms the address. The opening itself
     * is recorded (delivery) but confirms nothing: a scanner opens links too.
     */
    public function testDerMailLinkZeigtErstEinenKnopfUndHaeltDasOeffnenFest(): void
    {
        $this->tokenFromMail = true;

        [$data] = $this->render();

        self::assertTrue($data['gate']);
        self::assertSame('WEB-00054', $data['order_number']);
        self::assertArrayNotHasKey('order', $data, 'Vor dem Klick keine Bestellung.');
        self::assertSame([hash('sha256', $this->token)], $this->used);
        self::assertNull($this->connection->fetchOne('SELECT emailConfirmedAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));
    }

    public function testDerKnopfBestaetigtUndFuehrtZurBestellung(): void
    {
        $this->tokenFromMail = true;

        $redirect = $this->post(['consho_order_action' => 'view']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_order='.$this->token, $redirect->getResponse()->headers->get('Location'));
        self::assertNotNull($this->connection->fetchOne('SELECT emailConfirmedAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));

        // Afterwards no button any more, not even via the mail link.
        [$data] = $this->render();
        self::assertFalse($data['gate']);
        self::assertSame('WEB-00054', $data['order']['orderNumber']);
    }

    public function testDieUmleitungNachDemKaufZeigtNieDenKnopf(): void
    {
        [$data] = $this->render();

        self::assertFalse($data['gate']);
        self::assertSame([], $this->used);
    }

    /**
     * C-10: after the purchase the buyer lands on the page with the token of
     * the REDIRECT. A click there proves nothing about the address — nobody
     * has opened the mail at that point.
     */
    public function testEinKlickUeberDenUmleitungsTokenBestaetigtNichts(): void
    {
        $this->tokenFromMail = false;

        $this->post(['consho_order_action' => 'pdf', 'document' => '14']);

        self::assertNull($this->connection->fetchOne('SELECT emailConfirmedAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));
    }

    /**
     * The mail after the change gets a link of its own, and that one confirms —
     * even if the page was open via the redirect token.
     */
    public function testDieMailNachDemWechselTraegtEinenBestaetigendenToken(): void
    {
        $this->tokenFromMail = false;
        $this->change->method('change')->willReturn(new PaymentChanged(self::ORDER, 55, 56));

        $link = '';
        $this->mail->method('send')->willReturnCallback(static function (int $o, int|null $c, int|null $i, string $viewUrl) use (&$link): bool {
            $link = $viewUrl;

            return true;
        });

        $this->post(['consho_order_action' => 'change', 'method' => '3', 'consho_confirm' => '1']);

        self::assertSame([true], $this->issued);
        self::assertMatchesRegularExpression('#\?consho_order=[0-9a-f]{64}$#', $link);
        self::assertStringNotContainsString($this->token, $link);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function wegeOhneEinlass(): iterable
    {
        yield 'kein Token' => ['', self::ORDER];
        yield 'unbekanntes Token' => [str_repeat('cd', 32), self::ORDER];
        yield 'Bestellung eines anderen Shops' => [str_repeat('ab', 32), 3];
    }

    /**
     * D-6, T4: every path without entry leads to the same form "Neuen Link
     * anfordern" (request a new link) — before D-6 it was a 404. Nothing of
     * the order in it.
     *
     * @dataProvider wegeOhneEinlass
     */
    public function testJederWegOhneEinlassBietetEinenNeuenLinkAn(string $token, int $order): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 3, 'shop' => 2, 'orderNumber' => 'X-1']);

        $template = $this->template();
        $response = $this->controller($order)->respond($template, $this->model(), $this->request('GET', [], $token));
        $data = $template->getData();

        self::assertTrue($data['link_request']);
        self::assertNull($data['link_outcome']);
        self::assertArrayNotHasKey('order', $data);
        self::assertTrue($response->headers->getCacheControlDirective('no-store'));
        self::assertSame('noindex,nofollow', $this->context->get(HtmlHeadBag::class)->getMetaRobots());
    }

    public function testDieAnfrageLeitetOhneTokenMitErgebnisZurueck(): void
    {
        $this->linkRequest->expects(self::once())->method('request')
            ->with(self::SHOP, 'WEB-00054', 'kundin@example.org', '10.0.0.1')
            ->willReturn(OrderLinkOutcome::Requested);

        $request = $this->request('POST', ['consho_order_action' => 'requestLink', 'consho_link_number' => 'WEB-00054', 'consho_link_email' => 'kundin@example.org'], str_repeat('cd', 32));
        $request->server->set('REMOTE_ADDR', '10.0.0.1');

        try {
            $this->controller()->respond($this->template(), $this->model(), $request);
            self::fail('Die Anfrage muss weiterleiten.');
        } catch (RedirectResponseException $e) {
            $response = $e->getResponse();
            self::assertSame(303, $response->getStatusCode());
            self::assertSame(self::URL.'?consho_link=requested', $response->headers->get('Location'), 'ohne das tote Token');
        }
    }

    public function testDasErgebnisStehtNachDerWeiterleitungDa(): void
    {
        $template = $this->template();
        $this->controller()->respond($template, $this->model(), $this->request('GET', [], '', '&consho_link=tooMany'));
        self::assertSame('tooMany', $template->getData()['link_outcome']);

        $template = $this->template();
        $this->controller()->respond($template, $this->model(), $this->request('GET', [], '', '&consho_link=<b>frei</b>'));
        self::assertNull($template->getData()['link_outcome'], 'nur bekannte Ergebnisse');
    }

    public function testMitGueltigemTokenKeineAnfrage(): void
    {
        $this->linkRequest->expects(self::never())->method('request');

        $this->post(['consho_order_action' => 'requestLink', 'consho_link_number' => 'WEB-00054', 'consho_link_email' => 'kundin@example.org']);
    }

    public function testDasPdfKommtMitPrivatenKoepfen(): void
    {
        $response = $this->post(['consho_order_action' => 'pdf', 'document' => '14']);

        self::assertInstanceOf(ResponseException::class, $response);
        $answer = $response->getResponse();

        self::assertSame('%PDF-14', $answer->getContent());
        self::assertSame('application/pdf', $answer->headers->get('Content-Type'));
        self::assertSame('no-referrer', $answer->headers->get('Referrer-Policy'));
        self::assertStringContainsString('no-store', (string) $answer->headers->get('Cache-Control'));
    }

    public function testBelegeOhneNummerFehlenUndDerBelegDesModulsHatNurDasPdf(): void
    {
        // Block R (R16, R18): invoice data without a document, and a module's document.
        $this->connection->insert('tl_consho_document', ['id' => 16, 'pid' => self::ORDER, 'type' => 'invoice', 'documentNumber' => null, 'issuer' => 'off', 'issuedAt' => 1_758_900_000]);
        $this->connection->insert('tl_consho_document', ['id' => 17, 'pid' => self::ORDER, 'type' => 'invoice', 'documentNumber' => 'FB-7', 'issuer' => 'fake_books', 'issuedAt' => 1_758_950_000]);

        [$data] = $this->render();

        self::assertSame(['RE-00014', 'RK-00001', 'FB-7'], array_column($data['documents'], 'number'));
        self::assertSame([['pdf', 'html'], ['pdf', 'html'], ['pdf']], array_column($data['documents'], 'formats'));
    }

    public function testEinBelegEinerAnderenBestellungIstEin404(): void
    {
        self::assertInstanceOf(PageNotFoundException::class, $this->post(['consho_order_action' => 'pdf', 'document' => '99']));
    }

    public function testOhneBestaetigungWirdNichtGewechselt(): void
    {
        $this->change->expects(self::never())->method('change');

        $redirect = $this->post(['consho_order_action' => 'change', 'method' => '3']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertStringContainsString('consho_notice=confirmMissing', (string) $redirect->getResponse()->headers->get('Location'));
    }

    public function testEinWechselSchicktDieMailUndKehrtMitTokenZurueck(): void
    {
        $this->change->expects(self::once())->method('change')->with(self::ORDER, 3, 'de')->willReturn(new PaymentChanged(self::ORDER, 55, 56));
        $this->mail->expects(self::once())->method('send')->with(self::ORDER, 55, 56, self::matchesRegularExpression('#^'.preg_quote(self::URL, '#').'\?consho_order=[0-9a-f]{64}$#'));

        $redirect = $this->post(['consho_order_action' => 'change', 'method' => '3', 'consho_confirm' => '1']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_order='.$this->token.'&consho_notice=changed', $redirect->getResponse()->headers->get('Location'));
    }

    public function testEinAbgelehnterWechselNenntDenGrund(): void
    {
        $this->change->method('change')->willThrowException(PaymentChangeRefused::because(PaymentChangeBlock::NotOpen, self::ORDER));
        $this->mail->expects(self::never())->method('send');

        $redirect = $this->post(['consho_order_action' => 'change', 'method' => '3', 'consho_confirm' => '1']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertStringEndsWith('consho_notice=notOpen', (string) $redirect->getResponse()->headers->get('Location'));
    }

    public function testNachDemWechselStartetDieNeueArtUndFuehrtZumAnbieter(): void
    {
        $this->change->method('change')->willReturn(new PaymentChanged(self::ORDER, 55, null));
        $this->payments->expects(self::once())->method('start')->with(self::ORDER)->willReturn(PaymentStart::redirect('https://anbieter.test/pay'));

        $redirect = $this->post(['consho_order_action' => 'change', 'method' => '3', 'consho_confirm' => '1']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame('https://anbieter.test/pay', $redirect->getResponse()->headers->get('Location'));
    }

    public function testScheitertDerStartSagtDieSeiteEs(): void
    {
        $this->change->method('change')->willReturn(new PaymentChanged(self::ORDER, 55, null));
        $this->payments->method('start')->willReturn(PaymentStart::failed('down'));

        $redirect = $this->post(['consho_order_action' => 'change', 'method' => '3', 'consho_confirm' => '1']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertStringEndsWith('consho_notice=paymentFailed', (string) $redirect->getResponse()->headers->get('Location'));
    }

    public function testErneutVersuchenStartetNurWennErlaubt(): void
    {
        $this->payments->method('canRetry')->willReturnOnConsecutiveCalls(false, true);
        $this->payments->expects(self::once())->method('start')->willReturn(PaymentStart::redirect('https://anbieter.test/pay'));

        $refused = $this->post(['consho_order_action' => 'retry']);
        self::assertInstanceOf(RedirectResponseException::class, $refused);
        self::assertSame(self::URL.'?consho_order='.$this->token, $refused->getResponse()->headers->get('Location'));

        $started = $this->post(['consho_order_action' => 'retry']);
        self::assertInstanceOf(RedirectResponseException::class, $started);
        self::assertSame('https://anbieter.test/pay', $started->getResponse()->headers->get('Location'));
    }

    /**
     * The page only says what it can prove.
     *
     * @return iterable<string, array{AttemptState, AttemptState|null, string|null, bool}>
     */
    public static function onlineStaende(): iterable
    {
        // The button, as soon as PaymentStarter::canRetry() agrees — for an
        // open attempt only after expiry (checked there, mocked here).
        yield 'unterwegs, nichts belegt' => [AttemptState::Open, null, 'none', true];
        yield 'Rückkehr belegt, Meldung ausständig' => [AttemptState::Open, AttemptState::Succeeded, 'reported', false];
        yield 'fehlgeschlagen' => [AttemptState::Failed, null, 'failed', true];
        yield 'abgebrochen' => [AttemptState::Cancelled, AttemptState::Cancelled, 'cancelled', true];
        yield 'Vorkasse wartet auf die Überweisung' => [AttemptState::Pending, null, null, false];
    }

    /**
     * @dataProvider onlineStaende
     */
    public function testDieOnlineZahlungSagtNurWasBelegtIst(AttemptState $state, AttemptState|null $returned, string|null $expected, bool $retry): void
    {
        $this->attempts->attempts[1] = new PaymentAttempt(1, self::ORDER, 3, 'test', $state, '13.9000', 'EUR', '', '', '', $returned);
        $this->payments->method('canRetry')->willReturn(true);

        [$data] = $this->render();

        self::assertSame(null === $expected ? null : ['state' => $expected, 'retry' => $retry], $data['online']);
    }

    public function testBestaetigtErstMitGebuchterZahlung(): void
    {
        $this->attempts->attempts[1] = new PaymentAttempt(1, self::ORDER, 3, 'test', AttemptState::Succeeded, '13.9000', 'EUR', 'T-1', '', '');
        $this->connection->insert('tl_consho_payment', ['pid' => self::ORDER, 'amount' => '13.90']);

        [$data] = $this->render();

        self::assertSame('paid', $data['payment_state']);
        self::assertSame(['state' => 'confirmed', 'retry' => false], $data['online']);
    }

    /**
     * C-11: the newest attempt still belongs to the old method because the
     * new one could not start. "Abgebrochen" (cancelled) would speak of the
     * old one.
     */
    public function testEinVersuchDerAltenArtSagtNichts(): void
    {
        $this->attempts->attempts[1] = new PaymentAttempt(1, self::ORDER, 2, 'prepayment', AttemptState::Cancelled, '13.9000', 'EUR', '', '', '');

        [$data] = $this->render();

        self::assertNull($data['online']);
    }

    public function testOhneVersuchKeinSatz(): void
    {
        [$data] = $this->render();

        self::assertNull($data['online']);
    }

    public function testNurBekannteHinweiseWerdenGezeigt(): void
    {
        self::assertSame('changed', OrderController::noticeOf(Request::create('/?consho_notice=changed')));
        self::assertNull(OrderController::noticeOf(Request::create('/?consho_notice=<script>')));

        // LZ-1: a module's notice only if its language file brings it.
        $GLOBALS['TL_LANG']['CONSHO']['orderPage']['notice']['testReleased'] = 'Freigegeben.';
        self::assertSame('testReleased', OrderController::noticeOf(Request::create('/?consho_notice=testReleased')));
        self::assertNull(OrderController::noticeOf(Request::create('/?consho_notice=testUnknown')));
        unset($GLOBALS['TL_LANG']['CONSHO']['orderPage']['notice']['testReleased']);
        self::assertNull(OrderController::noticeOf(Request::create('/?consho_notice=testReleased')));
    }

    // ── Block Lizenz, LZ-1: what a module has delivered ───────────────────

    public function testDieSeiteZeigtWasDasModulAusgeliefertHat(): void
    {
        $this->withLicenceModule();

        self::assertSame(['KEY-1', 'nur Text'], array_column($this->render()[0]['entitlements'][0]['items'], 'text'));
    }

    public function testDieAktionDesModulsFuehrtMitSeinemHinweisZurSeite(): void
    {
        $this->withLicenceModule();

        $redirect = $this->post(['consho_order_action' => 'delivery', 'entitlement' => '1', 'item' => 'lic-1', 'delivery_action' => 'release']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertStringContainsString('consho_notice=testReleased', (string) $redirect->getResponse()->headers->get('Location'));
        self::assertSame(['act lic-1 release'], $this->module?->calls);

        self::assertInstanceOf(PageNotFoundException::class, $this->post(['consho_order_action' => 'delivery', 'entitlement' => '1', 'item' => 'note', 'delivery_action' => 'release']), 'nicht angeboten');
        self::assertSame(['act lic-1 release'], $this->module->calls);
    }

    public function testOhneKontodienstKeinKastenUndKeineAktion(): void
    {
        self::assertFalse($this->render()[0]['account']);
        self::assertInstanceOf(PageNotFoundException::class, $this->post(['consho_order_action' => 'createAccount']));
    }

    public function testKundenkontoAnlegenGehtMitDerBestellungZumDienstUndZurueck(): void
    {
        $account = $this->createMock(AccountFromOrder::class);
        $account->method('offered')->willReturn(true);
        $account->expects(self::once())->method('request')
            ->with(self::callback(static fn (array $order): bool => (string) self::ORDER === $order['id'] && 'kundin@example.org' === $order['email']), self::anything(), self::anything())
            ->willReturn(AccountRequestOutcome::Requested);
        $this->account = $account;

        self::assertTrue($this->render()[0]['account']);

        $redirect = $this->post(['consho_order_action' => 'createAccount', 'email' => 'fremd@example.org']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        $location = (string) $redirect->getResponse()->headers->get('Location');
        self::assertStringContainsString('consho_notice=accountRequested', $location);
        self::assertStringContainsString('consho_order='.$this->token, $location);
        self::assertSame('accountRequested', $this->render('&consho_notice=accountRequested')[0]['notice']);
    }

    public function testDerDownloadDesModulsUeberDieSeite(): void
    {
        $this->withLicenceModule();

        $response = $this->post(['consho_order_action' => 'download', 'entitlement' => '1', 'item' => 'lic-1']);

        self::assertInstanceOf(ResponseException::class, $response);
        self::assertSame('ZIP-lic-1', $response->getResponse()->getContent());
        self::assertInstanceOf(PageNotFoundException::class, $this->post(['consho_order_action' => 'download', 'entitlement' => '1', 'item' => 'note']));
    }

    // ── Withdrawal (D-2) ──────────────────────────────────────────────────

    public function testDieSeiteZeigtVertragWiderrufen(): void
    {
        [$data] = $this->render();

        self::assertSame('open', $data['withdrawal']['state']);
        self::assertFalse($data['withdrawal']['form']);
        self::assertSame($this->token, $data['withdrawal_token']);
    }

    /** E5: on the gate too, and the click confirms the address. */
    public function testVertragWiderrufenSchonAufDemTor(): void
    {
        $this->tokenFromMail = true;

        self::assertSame('open', $this->render()[0]['withdrawal']['state']);

        $redirect = $this->post(['consho_order_action' => 'withdrawStart']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_order='.$this->token.'&consho_withdraw=1', $redirect->getResponse()->headers->get('Location'));
        self::assertNotNull($this->connection->fetchOne('SELECT emailConfirmedAt FROM tl_consho_order WHERE id = ?', [self::ORDER]));
    }

    public function testDieErklaerungIstVorbefuelltUndNenntNurWare(): void
    {
        [$data] = $this->render('&consho_withdraw=1');

        self::assertTrue($data['withdrawal']['form']);
        self::assertSame('Erika Muster', $data['withdrawal']['name']);
        self::assertSame('kundin@example.org', $data['withdrawal']['email']);
        self::assertSame([['id' => 1, 'name' => 'Wanderstock', 'left' => '1', 'max' => '1', 'whole' => false]], $data['withdrawal']['items'], 'die Versandzeile nicht');
    }

    public function testWiderrufBestaetigenSpeichertUndMeldetDenEingang(): void
    {
        $redirect = $this->post(['consho_order_action' => 'withdraw', 'consho_withdraw_name' => 'Erika Muster', 'consho_withdraw_scope' => 'all']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_order='.$this->token.'&consho_notice=withdrawn', $redirect->getResponse()->headers->get('Location'));
        self::assertSame('WEB-00054-W1', $this->connection->fetchOne('SELECT number FROM tl_consho_withdrawal'));

        $withdrawal = $this->render()[0]['withdrawal'];

        self::assertCount(1, $withdrawal['received']);
        self::assertTrue($withdrawal['received'][0]['confirmed']);

        // Abo-8: "die ganze Bestellung" (the whole order) is withdrawn — no button any more.
        self::assertNull($withdrawal['state']);
    }

    /** Abo-8: withdrawn item by item, everything is gone too — no button any more. */
    public function testNachWiderrufAllerPositionenKeinKnopfMehr(): void
    {
        $this->post(['consho_order_action' => 'withdraw', 'consho_withdraw_name' => 'E', 'consho_withdraw_scope' => 'items', 'consho_withdraw_quantity' => [1 => '1']]);

        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
        self::assertNull($this->render()[0]['withdrawal']['state']);
    }

    /**
     * "Einzelne Artikel" (single articles) without a quantity is not "the
     * whole order" — that would be the opposite of the choice. Back to the
     * declaration, nothing stored.
     *
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function incompleteDeclarations(): iterable
    {
        yield 'Artikel ohne Menge' => [['consho_withdraw_name' => 'E', 'consho_withdraw_scope' => 'items', 'consho_withdraw_quantity' => [1 => '0']], 'withdrawNothingSelected'];
        yield 'ohne Namen' => [['consho_withdraw_name' => ' ', 'consho_withdraw_scope' => 'all'], 'withdrawNameMissing'];
        yield 'mehr als bestellt' => [['consho_withdraw_name' => 'E', 'consho_withdraw_scope' => 'items', 'consho_withdraw_quantity' => [1 => '5']], 'withdrawInvalid'];

        // D-2: nested, this was a 500.
        yield 'verschachtelte Menge' => [['consho_withdraw_name' => 'E', 'consho_withdraw_scope' => 'items', 'consho_withdraw_quantity' => [1 => ['x' => '1']]], 'withdrawInvalid'];
    }

    /**
     * @param array<string, mixed> $post
     *
     * @dataProvider incompleteDeclarations
     */
    public function testUnvollstaendigeErklaerungGehtZurueck(array $post, string $notice): void
    {
        $redirect = $this->post(['consho_order_action' => 'withdraw'] + $post);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertSame(self::URL.'?consho_order='.$this->token.'&consho_notice='.$notice.'&consho_withdraw=1', $redirect->getResponse()->headers->get('Location'));
        self::assertSame(0, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_withdrawal'));
    }

    /**
     * WA, B2: after the window the button stays, with the notice "Nach
     * unseren Daten ist die Frist abgelaufen. Der Widerruf wird entsprechend
     * geprüft." (according to our data the period has expired; the withdrawal
     * will be examined accordingly) — and the declaration is stored. Before
     * that, only a sentence with the shop's address stood there.
     */
    public function testNachDemFensterDerKnopfMitHinweisUndDieErklaerungZaehlt(): void
    {
        [$data] = $this->render();
        self::assertFalse($data['withdrawal']['late'], 'im Fenster kein Hinweis');

        $shipped = time() - 40 * 86400;
        $this->connection->insert('tl_consho_delivery', ['id' => 1, 'pid' => self::ORDER, 'shippedAt' => $shipped, 'reportedAt' => $shipped]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 1, 'orderItem' => 1, 'quantity' => 1]);

        [$data] = $this->render('&consho_withdraw=1');

        self::assertSame('open', $data['withdrawal']['state']);
        self::assertTrue($data['withdrawal']['late']);
        self::assertTrue($data['withdrawal']['form']);

        $redirect = $this->post(['consho_order_action' => 'withdraw', 'consho_withdraw_name' => 'E', 'consho_withdraw_scope' => 'all']);

        self::assertInstanceOf(RedirectResponseException::class, $redirect);
        self::assertStringContainsString('consho_notice=withdrawn', (string) $redirect->getResponse()->headers->get('Location'));
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT afterWindow FROM tl_consho_withdrawal'));
    }

    /**
     * Abo-4 (P31): the order page of a follow-up order names the subscription
     * and the service period — as the invoice records them.
     */
    public function testEineFolgebestellungNenntAboUndZeitraum(): void
    {
        self::assertSame('', $this->render()[0]['order']['subscription'], 'ein Kauf nennt keines');

        $this->invoiceSubscription = ['number' => 'WEB-00084-1', 'from' => '2026-10-29', 'to' => '2026-11-29'];
        $order = $this->render()[0]['order'];

        self::assertSame('WEB-00084-1', $order['subscription']);
        self::assertSame('29.10.2026 – 29.11.2026', str_replace(["\u{a0}", "\u{202f}"], ' ', $order['servicePeriod']));
    }

    /** E7 */
    public function testStorniertOhneVersandKeinWiderruf(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 1], ['id' => self::ORDER]);

        self::assertNull($this->render()[0]['withdrawal']['state']);
    }

    /**
     * LZ-1: an entitlement of the order with a module that delivers text,
     * download and button.
     */
    private function withLicenceModule(): void
    {
        // Only what the setup does not bring already (the withdrawal brings
        // the entitlements).
        foreach (['tl_consho_product', 'tl_consho_download', 'tl_consho_key', 'tl_consho_entitlement', 'tl_consho_subscription'] as $table) {
            if (!$this->connection->createSchemaManager()->tablesExist([$table])) {
                DcaSchema::create($this->connection, $table);
            }
        }

        $this->connection->insert('tl_consho_entitlement', ['id' => 1, 'pid' => self::ORDER, 'orderItem' => 1, 'product' => 1, 'quantity' => '1', 'deliveryType' => FakePresentableHandler::getType(), 'validFrom' => 1_000]);

        $module = new FakePresentableHandler();
        $this->module = $module;
        $this->downloads = new OrderPageDownloads(
            $this->connection,
            new DigitalFiles($this->connection, new ArchiveFolderProtector(sys_get_temp_dir()), sys_get_temp_dir()),
            new KeyPoolHandler($this->connection),
            null,
            new DeliveryHandlers(new ServiceLocator([FakePresentableHandler::getType() => static fn (): FakePresentableHandler => $module])),
        );
    }

    public function testOhneRechnungVonConshoVersprichtDieSeiteKeineBelege(): void
    {
        // Block R (R16): the invoice row carries no number; the reference
        // falls back to the order number, as in the confirmation mail.
        $this->sourceSetup = static function (FakeConfirmationSource $source): void {
            $source->invoiceRow['documentNumber'] = '';
        };
        $this->connection->update('tl_consho_shop', ['invoicing' => 'off'], ['id' => self::SHOP]);
        $this->policy = new InvoicingPolicy($this->connection, new InvoiceProviders(new ServiceLocator([])));

        [$data] = $this->render('&consho_notice=changed');

        self::assertFalse($data['invoices_by_mail']);
        self::assertSame('changedElsewhere', $data['notice']);
        self::assertSame('WEB-00054', $data['reference']);

        // Consho's invoices: the notice and the page as before.
        $this->connection->update('tl_consho_shop', ['invoicing' => 'consho'], ['id' => self::SHOP]);
        [$data] = $this->render('&consho_notice=changed');

        self::assertTrue($data['invoices_by_mail']);
        self::assertSame('changed', $data['notice']);
    }

    /**
     * @return array{0: array<string, mixed>, 1: Response}
     */
    private function render(string $query = ''): array
    {
        $template = $this->template();
        $response = $this->controller()->respond($template, $this->model(), $this->request('GET', [], null, $query));

        return [$template->getData(), $response];
    }

    /**
     * @param array<string, mixed> $post a form body — quantities come nested (consho_withdraw_quantity[1])
     */
    private function post(array $post): \Throwable|null
    {
        try {
            $this->controller()->respond($this->template(), $this->model(), $this->request('POST', $post));
        } catch (\Throwable $e) {
            return $e;
        }

        return null;
    }

    private function template(): FragmentTemplate
    {
        return new FragmentTemplate('content_element/consho_order', static fn (): Response => new Response());
    }

    /**
     * @param array<string, string> $post
     */
    private function request(string $method = 'GET', array $post = [], string|null $token = null, string $query = ''): Request
    {
        $request = Request::create(self::URL.'?consho_order='.($token ?? $this->token).$query, $method, $post);
        $request->attributes->set('pageModel', $this->pageModel(self::ORDER_PAGE, []));

        return $request;
    }

    private function model(): ContentModel
    {
        $model = $this->createMock(ContentModel::class);
        $model->method('row')->willReturn(['id' => 42]);

        return $model;
    }

    private function controller(int $order = self::ORDER): OrderController&TestableOrderElement
    {
        $hash = hash('sha256', $this->token);
        $confirms = $this->tokenFromMail;
        $tokens = $this->createStub(TokenRepositoryInterface::class);
        $tokens->method('findByHash')->willReturnCallback(
            static fn (string $h): array|null => $h === $hash ? ['pid' => $order, 'expiresAt' => null, 'usedAt' => null, 'confirmsEmail' => $confirms] : null,
        );
        $tokens->method('markUsed')->willReturnCallback(function (string $h): void {
            $this->used[] = $h;
        });
        $tokens->method('store')->willReturnCallback(function (int $o, $p, string $h, int $c, int|null $e, bool $confirmsEmail): void {
            $this->issued[] = $confirmsEmail;
        });

        $documents = new class implements DocumentDeliveryInterface {
            public function sentElsewhere(int $documentId): bool
            {
                return false;
            }

            public function pdf(int $documentId): array
            {
                return ['pdf' => '%PDF-'.$documentId, 'fileName' => 'RE-00014.pdf', 'fromArchive' => false, 'archiveProblem' => false, 'logoMissing' => false];
            }

            public function html(int $documentId): array
            {
                return ['html' => '<html>'.$documentId.'</html>', 'logoMissing' => false];
            }
        };

        $source = new FakeConfirmationSource();
        $source->order['id'] = self::ORDER;

        if (null !== $this->invoiceSubscription && null !== $source->invoiceRow) {
            $source->invoiceRow['payload']['subscription'] = $this->invoiceSubscription;
        }

        if (null !== $this->sourceSetup) {
            ($this->sourceSetup)($source);
        }

        $contexts = $this->createStub(ResponseContextAccessor::class);
        $contexts->method('getResponseContext')->willReturn($this->context);

        $urls = $this->createStub(ContentUrlGenerator::class);
        $urls->method('generate')->willReturnCallback(
            static fn (object $page, array $parameters = [], int $type = UrlGeneratorInterface::ABSOLUTE_PATH): string => self::URL.'?'.http_build_query($parameters),
        );

        return new class(
            new TokenValidator($tokens),
            new DbalOrderPageRepository($this->connection),
            new ShopResolver($this->framework()),
            new ShopLanguages($this->connection),
            $source,
            new DocumentPayloadBuilder(),
            new InvoiceView(new MoneyFormatter(), new DocumentPayloadBuilder()),
            new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()),
            $documents,
            $this->change,
            $this->mail,
            new MoneyFormatter(),
            $urls,
            $contexts,
            new TokenGenerator($tokens),
            $this->payments,
            $this->attempts,
            $this->withdrawal(),
            $this->linkRequest,
            $this->downloads,
            null,
            $this->durability,
            $this->account,
            $this->policy,
        ) extends OrderController implements TestableOrderElement {
            public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response
            {
                return $this->getResponse($template, $model, $request);
            }
        };
    }

    /**
     * The real withdrawal services on the test database; only the mail and
     * the link in it are doubles (their content: OrderEventMailTest).
     */
    private function withdrawal(): OrderPageWithdrawal
    {
        $windows = new WithdrawalWindowReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));
        $repository = new DbalWithdrawalRepository($this->connection);

        $mail = $this->createStub(OrderEventMail::class);
        $mail->method('withdrawalReceived')->willReturn(true);

        $links = $this->createStub(OrderPageLink::class);
        $links->method('forMail')->willReturn(self::URL.'?consho_order=mail');

        return new OrderPageWithdrawal(
            $windows,
            $repository,
            new WithdrawalRecorder($this->connection, $windows, $repository, $mail, $links),
            new MoneyFormatter(),
        );
    }

    private function framework(): ContaoFramework
    {
        $pages = [self::ROOT_PAGE => $this->pageModel(self::ROOT_PAGE, ['consho_shop' => self::SHOP, 'consho_currency' => 'EUR'])];

        $pageAdapter = $this->createMock(Adapter::class);
        $pageAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $parameters): PageModel|null => $pages[(int) ($parameters[0] ?? 0)] ?? null,
        );

        $shop = $this->createMock(ShopModel::class);
        $shop->method('__get')->willReturnMap([['id', self::SHOP], ['currency', 'EUR'], ['country', 'AT']]);

        $shopAdapter = $this->createMock(Adapter::class);
        $shopAdapter->method('__call')->willReturnCallback(
            static fn (string $method, array $parameters): ShopModel|null => self::SHOP === (int) ($parameters[0] ?? 0) ? $shop : null,
        );

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnMap([
            [PageModel::class, $pageAdapter],
            [ShopModel::class, $shopAdapter],
        ]);

        return $framework;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pageModel(int $id, array $row): PageModel
    {
        $page = $this->createMock(PageModel::class);
        $page->method('__get')->willReturnMap([
            ['id', $id],
            ['type', self::ROOT_PAGE === $id ? 'root' : 'regular'],
            ['rootId', self::ROOT_PAGE],
            ['language', 'de'],
        ]);
        $page->method('row')->willReturn($row + ['id' => $id]);

        return $page;
    }
}

interface TestableOrderElement
{
    public function respond(FragmentTemplate $template, ContentModel $model, Request $request): Response;
}
