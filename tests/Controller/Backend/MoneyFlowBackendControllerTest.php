<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\MoneyFlowBackendController;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\MoneyFlows;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\PaymentClaim;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRefundingHandler;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * "Geldflüsse" at an order (block Geldflüsse, 3.6), with the actions of the
 * former "Nachträge" (spec 9) — real template, real RefundPayout and
 * MoneyFlows.
 */
final class MoneyFlowBackendControllerTest extends TestCase
{
    private Connection $connection;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<int> */
    private array $recorded = [];

    private string|null $outstanding = null;

    private string|null $claimAnswer = null;

    /** @var list<array{int, string}> */
    private array $claimed = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_status', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_payment', 'tl_consho_payment_attempt', 'tl_consho_provider_event'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        WithdrawalSchema::create($this->connection);

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'currency' => 'EUR', 'language' => 'de', 'grandTotal' => '30.0000']);
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 10, 'pid' => 1, 'tokenHash' => 'h10', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment', ['id' => 5, 'pid' => 1, 'amount' => '30.0000', 'receivedAt' => 100, 'attempt' => 10, 'reference' => 'tr_1', 'method' => 'Karte']);
        $this->connection->insert('tl_consho_provider_event', ['id' => 1, 'pid' => 1, 'payment' => 5, 'kind' => 'chargeback', 'reference' => 'chb_1', 'amount' => '30.0000', 'currency' => 'EUR', 'reason' => 'MD06', 'occurredAt' => 1_800_000_000, 'state' => 'open']);
        $this->connection->insert('tl_consho_provider_event', ['id' => 2, 'pid' => 1, 'payment' => 5, 'kind' => 'refund', 'reference' => 're_x', 'amount' => '10.0000', 'currency' => 'EUR', 'occurredAt' => 1_800_000_100, 'state' => 'open']);

        require __DIR__.'/../../../contao/languages/de/tl_consho_order.php';
        require __DIR__.'/../../../contao/languages/de/tl_consho_payment.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testThePageListsTheEventsWithWhatCanBeDone(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'payoutState' => 'manual', 'reference' => 'W-1']);
        // The chargeback's row, as ProviderEventBooker writes it.
        $this->connection->insert('tl_consho_payment', ['id' => 6, 'pid' => 1, 'amount' => '-30.0000', 'method' => 'chargeback', 'refundOf' => 5, 'reference' => 'MD06', 'payoutReference' => 'chb_1', 'receivedAt' => 1_800_000_000]);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());

        self::assertStringContainsString('chb_1', $html);
        self::assertStringContainsString('zurückgebucht', $html);
        // The row's reference is the bank's reason the event names as well: once.
        self::assertSame(1, substr_count($html, 'MD06'));
        self::assertStringContainsString('re_x', $html);
        self::assertStringContainsString('In Consho erfassen', $html);
        self::assertStringContainsString('<option value="8">', $html);
        self::assertStringContainsString('Gutschrift oder Steuerkorrektur entsteht dabei nicht', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
    }

    /**
     * Geldflüsse 3.6: the balance on top, every movement in time below —
     * a refund with its way and its withdrawal, a correction with its
     * reason, a refund made at the provider not booked yet.
     */
    public function testTheBalanceAndTheMovementsInTime(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 4, 'pid' => 1, 'number' => 'WEB-1-W1', 'state' => 'accepted', 'refundedAt' => 300, 'refundDocument' => 12]);
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-5.0000', 'method' => 'refund', 'refundOf' => 5, 'payoutState' => 'manual', 'reference' => 'WEB-1-W1', 'receivedAt' => 300]);
        $this->connection->insert('tl_consho_payment', ['id' => 9, 'pid' => 1, 'amount' => '-2.0000', 'method' => 'correction', 'refundOf' => 5, 'reference' => 'Tippfehler', 'receivedAt' => 200]);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=moneyFlows&id=1'))($this->dc());

        // Received 30 less the correction, 5 paid back, 10 back at the provider.
        self::assertMatchesRegularExpression('#Bezahlt</td>\s*<td[^>]*>28,00\s€#u', $html);
        // Still open: not "ausgeglichen", neither in the legend nor as a row.
        self::assertStringNotContainsStringIgnoringCase('ausgeglichen', $html);
        self::assertMatchesRegularExpression('#Zurückgezahlt</td>\s*<td[^>]*>5,00\s€#u', $html);
        self::assertMatchesRegularExpression('#Beim Zahlungsanbieter erstattet, noch nicht erfasst</td>\s*<td[^>]*>10,00\s€#u', $html);
        self::assertMatchesRegularExpression('#<strong>Offen</strong></td>\s*<td[^>]*><strong>7,00\s€#u', $html);

        $payment = strpos($html, '<strong>Zahlung</strong>');
        // Each detail on a line of its own, grey.
        self::assertMatchesRegularExpression('#<strong>Korrektur</strong>\s*<br><span class="tl_gray">Grund: Tippfehler</span>#u', $html);
        $correction = strpos($html, '<strong>Korrektur</strong>');
        $refund = strpos($html, '<strong>Rückzahlung</strong>');
        $foreign = strpos($html, '<strong>Erstattung beim Zahlungsanbieter</strong>');
        self::assertNotFalse($payment);
        self::assertTrue($payment < $correction && $correction < $refund && $refund < $foreign, 'zeitlich: '.json_encode([$payment, $correction, $refund, $foreign]));

        self::assertStringContainsString('von Hand', $html, 'der Weg der Rückzahlung');
        self::assertStringContainsString('href="/contao?do=consho_withdrawal&amp;key=processWithdrawal&amp;id=4">Widerruf WEB-1-W1</a>', $html);
        self::assertStringContainsString('key=invoicePdf&amp;id=12">Rechnungskorrektur</a>', $html);
        self::assertMatchesRegularExpression('#zur Zahlung über 30,00\s€ vom#u', $html);

        // The operations of the payment list, where they apply (PaymentActions).
        self::assertStringContainsString('href="/contao?do=consho_order&amp;table=tl_consho_payment&amp;key=refundPayment&amp;id=5"', $html, '13 € left at the payment');
        self::assertStringNotContainsString('key=refundPayment&amp;id=8"', $html, 'a refund is not refunded');
        self::assertStringContainsString('href="/contao?do=consho_order&amp;table=tl_consho_payment&amp;act=edit&amp;id=9"', $html, 'not locked');
        self::assertStringContainsString('href="/contao?do=consho_order&amp;table=tl_consho_payment&amp;act=create&amp;mode=2&amp;pid=1" class="header_new">Zahlung erfassen</a>', $html);
        self::assertStringNotContainsString('Zahlungsliste', $html);
    }

    /**
     * What the payment list offered besides: deleting a row not locked yet —
     * as Contao's DELETE with the token in the body, asked first — and the
     * details of every row.
     */
    public function testDeletingAndDetailsAsInThePaymentList(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 9, 'pid' => 1, 'amount' => '20.0000', 'method' => 'Überweisung', 'receivedAt' => 200]);
        $this->connection->update('tl_consho_payment', ['lockedAt' => 150], ['id' => 5]);
        // Contao's text, as the list's delete button uses it.
        $GLOBALS['TL_LANG']['MSC']['deleteConfirm'] = 'Soll der Eintrag ID %s wirklich gelöscht werden?';

        $html = $this->controller(Request::create('/contao?do=consho_order&key=moneyFlows&id=1'))($this->dc());

        self::assertMatchesRegularExpression('#<form action="/contao\?do=consho_order&amp;table=tl_consho_payment&amp;act=delete&amp;id=9" method="post"[^>]*>\s*<input type="hidden" name="_method" value="DELETE">\s*<input type="hidden" name="REQUEST_TOKEN" value="tok">#', $html);
        self::assertStringContainsString('data-confirm="Soll der Eintrag ID 9 wirklich gelöscht werden?"', $html);
        self::assertStringNotContainsString('act=delete&amp;id=5"', $html, 'locked');
        self::assertStringContainsString('href="/contao?do=consho_order&amp;table=tl_consho_payment&amp;act=show&amp;id=5"', $html, 'details also when locked');
        self::assertStringContainsString('href="/contao?do=consho_order&amp;table=tl_consho_payment&amp;act=show&amp;id=9"', $html);
    }

    /**
     * A payment not locked yet, but with a refund against it, offers neither
     * editing nor deleting: either would leave a refund of nothing.
     */
    public function testAPaymentWithARefundIsNeitherEditedNorDeleted(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 9, 'pid' => 1, 'amount' => '20.0000', 'method' => 'Überweisung', 'receivedAt' => 200]);
        $this->connection->insert('tl_consho_payment', ['id' => 10, 'pid' => 1, 'amount' => '-20.0000', 'refundOf' => 9, 'receivedAt' => 300, 'lockedAt' => 300]);
        $GLOBALS['TL_LANG']['MSC']['deleteConfirm'] = 'Delete ID %s?';

        $html = $this->controller(Request::create('/contao?do=consho_order&key=moneyFlows&id=1'))($this->dc());

        self::assertStringNotContainsString('act=delete&amp;id=9"', $html);
        self::assertStringNotContainsString('act=edit&amp;id=9"', $html);
        self::assertStringContainsString('act=show&amp;id=9"', $html);
    }

    public function testACancelledPaidOrderShowsWhatIsTooMuch(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 100], ['id' => 1]);
        $this->connection->delete('tl_consho_provider_event', ['id' => 2]);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=moneyFlows&id=1'))($this->dc());

        self::assertStringContainsString('Forderung (storniert)', $html);
        self::assertMatchesRegularExpression('#<strong>Zu viel bezahlt</strong></td>\s*<td[^>]*><strong>30,00\s€#u', $html);
    }

    public function testAStandingChargebackOffersTheClaim(): void
    {
        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());
        self::assertStringNotContainsString('name="consho_day"', $html, 'nothing open');

        $this->outstanding = '30.0000';
        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());

        self::assertStringContainsString('Zahlung einfordern', $html);
        self::assertStringContainsString('30,00', $html);
        self::assertStringContainsString('value="'.PaymentClaim::defaultDay(time()).'"', $html);
    }

    public function testClaimingAsksTheCustomer(): void
    {
        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'claim', 'consho_day' => '2027-01-20']))($this->dc()));

        self::assertSame([[1, '2027-01-20']], $this->claimed);
        self::assertSame(['addConfirmation', 'Die Zahlungsaufforderung ist versendet.'], $this->messages[0]);
    }

    public function testAClaimWhoseMailFailedSaysSo(): void
    {
        $this->claimAnswer = 'mailNotSent';

        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'claim', 'consho_day' => '2027-01-20']))($this->dc()));

        self::assertSame('addInfo', $this->messages[0][0]);
        self::assertStringContainsString('Zahlungsziel ist gesetzt', $this->messages[0][1]);
    }

    public function testRecordingBooksTheRefund(): void
    {
        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'record', 'consho_event' => '2']))($this->dc()));

        self::assertSame('recorded', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = 2'));
        self::assertSame('re_x', $this->connection->fetchOne("SELECT payoutReference FROM tl_consho_payment WHERE method = 'refund'"));
        self::assertSame([1], $this->recorded, 'the balance changed');
        self::assertSame(['addConfirmation', 'Die Erstattung ist in Consho erfasst.'], $this->messages[0]);
    }

    public function testAssigningTiesTheRow(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'payoutState' => 'manual']);

        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'assign', 'consho_event' => '2', 'consho_row' => '8']))($this->dc()));

        self::assertSame('assigned', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = 2'));
        self::assertSame('re_x', $this->connection->fetchOne('SELECT payoutReference FROM tl_consho_payment WHERE id = 8'));
    }

    /**
     * Geldflüsse J3: a refund that names no payment is offered the payments
     * whose rest covers it, and tied to the one chosen.
     */
    public function testARefundWithoutPaymentIsAssignedToOne(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-4.0000', 'method' => 'refund', 'payoutState' => 'manual', 'receivedAt' => 300]);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=moneyFlows&id=1'))($this->dc());

        self::assertStringContainsString('<input type="hidden" name="consho_action" value="assignPayment">', $html);
        self::assertStringContainsString('<input type="hidden" name="consho_row" value="8">', $html);
        // 30 received, 10 already back at the provider: 20 left.
        self::assertMatchesRegularExpression('#<option value="5">30,00\s€ vom [0-9.]+, offen 20,00\s€</option>#u', $html);

        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'assignPayment', 'consho_row' => '8', 'consho_payment' => '5']))($this->dc()));

        self::assertSame(5, (int) $this->connection->fetchOne('SELECT refundOf FROM tl_consho_payment WHERE id = 8'));
        self::assertSame(['addConfirmation', 'Die Rückzahlung ist der Zahlung zugeordnet.'], $this->messages[0]);
        self::assertSame([], $this->recorded, 'no sum changed');

        $html = $this->controller(Request::create('/contao?do=consho_order&key=moneyFlows&id=1'))($this->dc());
        self::assertStringNotContainsString('value="assignPayment"', $html);
    }

    /**
     * Geldflüsse H1, H2: while a withdrawal waits for its refund, the
     * foreign refund leads there, ticked, and is not recorded here.
     */
    public function testAWaitingWithdrawalTakesTheRefund(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 7, 'pid' => 1, 'number' => 'WEB-1-W1', 'state' => 'accepted']);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 8, 'pid' => 1, 'number' => 'WEB-1-W2', 'state' => 'accepted', 'refundedAt' => 100]);
        $this->connection->insert('tl_consho_withdrawal', ['id' => 9, 'pid' => 1, 'number' => 'WEB-1-W3', 'state' => 'rejected']);
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'payoutState' => 'manual']);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());

        self::assertStringContainsString('href="/contao?do=consho_withdrawal&amp;key=processWithdrawal&amp;id=7&amp;consho_foreign=2"', $html);
        self::assertStringContainsString('Zum Widerruf WEB-1-W1', $html);
        self::assertStringNotContainsString('WEB-1-W2', $html, 'erstattet');
        self::assertStringNotContainsString('WEB-1-W3', $html, 'abgelehnt');
        self::assertStringNotContainsString('In Consho erfassen', $html);
        self::assertStringContainsString('<option value="8">', $html, 'Zuordnen bleibt');

        $this->redirected(fn () => $this->controller($this->post(['consho_action' => 'record', 'consho_event' => '2']))($this->dc()));

        self::assertSame('open', $this->connection->fetchOne('SELECT state FROM tl_consho_provider_event WHERE id = 2'));
        self::assertSame('addError', $this->messages[0][0]);
        self::assertStringContainsString('wartet ein Widerruf', $this->messages[0][1]);
    }

    /**
     * RK-4: the same while a decided rescission or price reduction waits;
     * a refund booked by a complaint links to it.
     */
    public function testAWaitingComplaintTakesTheRefund(): void
    {
        $this->connection->insert('tl_consho_complaint', ['id' => 4, 'pid' => 1, 'number' => 'WEB-1-R1', 'state' => 'inProgress', 'remedy' => 'reduction', 'decidedAt' => 90]);
        $this->connection->insert('tl_consho_complaint', ['id' => 5, 'pid' => 1, 'number' => 'WEB-1-R2', 'state' => 'open']);
        $this->connection->insert('tl_consho_complaint', ['id' => 6, 'pid' => 1, 'number' => 'WEB-1-R3', 'state' => 'resolved', 'remedy' => 'rescission', 'decidedAt' => 90, 'refundedAt' => 95, 'refundDocument' => 12]);
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'payoutState' => 'manual', 'reference' => 'WEB-1-R3', 'receivedAt' => 95]);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());

        self::assertStringContainsString('href="/contao?do=consho_complaint&amp;key=processComplaint&amp;id=4&amp;consho_foreign=2"', $html);
        self::assertStringContainsString('Zur Reklamation WEB-1-R1', $html);
        self::assertStringNotContainsString('Zur Reklamation WEB-1-R2', $html, 'nicht entschieden');
        self::assertStringNotContainsString('In Consho erfassen', $html);
        self::assertStringContainsString('href="/contao?do=consho_complaint&amp;key=processComplaint&amp;id=6">Reklamation WEB-1-R3</a>', $html);
        self::assertStringContainsString('key=invoicePdf&amp;id=12', $html);
    }

    public function testAtACancelledOrderRecordingIsTheCancellationsRefund(): void
    {
        $this->connection->update('tl_consho_order', ['cancelledAt' => 100], ['id' => 1]);

        $html = $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'))($this->dc());

        self::assertStringContainsString('In Consho erfassen', $html);
        self::assertStringContainsString('Rückzahlung dieser Stornierung', $html);
    }

    public function testAnEventOfAnotherOrderIsNotFound(): void
    {
        $this->connection->insert('tl_consho_provider_event', ['id' => 3, 'pid' => 2, 'kind' => 'refund', 'reference' => 're_other', 'amount' => '1.0000', 'state' => 'open']);

        $this->expectException(NotFoundHttpException::class);

        $this->controller($this->post(['consho_action' => 'record', 'consho_event' => '3']))($this->dc());
    }

    public function testRecordingNeedsThePermissionToCreatePayments(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller($this->post(['consho_action' => 'record', 'consho_event' => '2']), payments: false)($this->dc());
    }

    public function testAssigningAPaymentNeedsThePermissionToEditPayments(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '-4.0000', 'method' => 'refund', 'payoutState' => 'manual', 'receivedAt' => 300]);

        try {
            $this->controller($this->post(['consho_action' => 'assignPayment', 'consho_row' => '8', 'consho_payment' => '5']), payments: false)($this->dc());
            self::fail('expected AccessDeniedHttpException');
        } catch (AccessDeniedHttpException) {
        }

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT refundOf FROM tl_consho_payment WHERE id = 8'));
    }

    public function testThePageNeedsThePermissionOnTheOrder(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller(Request::create('/contao?do=consho_order&key=followUps&id=1'), order: false)($this->dc());
    }

    /**
     * @param array<string, string> $data
     */
    private function post(array $data): Request
    {
        return Request::create('/contao?do=consho_order&key=followUps&id=1', 'POST', $data);
    }

    private function redirected(\Closure $call): void
    {
        try {
            $call();
            self::fail('POST redirects');
        } catch (RedirectResponseException) {
        }
    }

    private function controller(Request $request, bool $order = true, bool $payments = true): MoneyFlowBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (mixed $attribute): bool => match ($attribute) {
            ContaoCorePermissions::DC_PREFIX.'tl_consho_order' => $order,
            ContaoCorePermissions::DC_PREFIX.'tl_consho_payment' => $payments,
            default => false,
        });

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            if ('generate' !== $name) {
                $messages[] = [$name, (string) $args[0]];
            }

            return '';
        });
        $system = $this->createMock(Adapter::class);
        $system->method('__call')->willReturn('/contao?do=consho_order');

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturnCallback(static fn (string $class): Adapter => Message::class === $class ? $message : $system);

        $loader = new FilesystemLoader();
        $loader->addPath(__DIR__.'/../../../contao/templates', 'Contao');
        $twig = new Environment($loader, ['strict_variables' => true]);
        $twig->addGlobal('contao', ['request_token' => 'tok']);

        $methods = new class implements PaymentMethodLookupInterface {
            public function byId(int $methodId): PaymentMethod|null
            {
                return 1 === $methodId ? new PaymentMethod(1, 'card', 'Visa', []) : null;
            }
        };

        $orders = $this->createStub(PaymentChangeRepositoryInterface::class);
        $orders->method('lockOrder')->willReturn(['id' => '1']);

        $recorded = $this->createMock(PaymentRecorded::class);
        $calls = &$this->recorded;
        $recorded->method('handle')->willReturnCallback(static function (int $orderId) use (&$calls): void {
            $calls[] = $orderId;
        });

        $card = new FakeRefundingHandler();
        $payout = new RefundPayout($this->connection, $orders, $methods, new PaymentHandlers(new ServiceLocator([
            'card' => static fn (): PaymentHandlerInterface => $card,
        ])));

        $claims = $this->createMock(PaymentClaim::class);
        $claims->method('outstanding')->willReturnCallback(fn (): string|null => $this->outstanding);
        $claimed = &$this->claimed;
        $claims->method('claim')->willReturnCallback(function (int $orderId, string $day) use (&$claimed): string|null {
            $claimed[] = [$orderId, $day];

            return $this->claimAnswer;
        });

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $params): string => '/contao?'.http_build_query($params));

        $flows = new MoneyFlows($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator()));

        return new MoneyFlowBackendController($this->connection, $security, $framework, $requests, $twig, $payout, $recorded, $claims, new MoneyFormatter(), $router, $flows);
    }

    private function dc(): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => 1,
            'table' => 'tl_consho_order',
            default => null,
        });

        return $dc;
    }
}
