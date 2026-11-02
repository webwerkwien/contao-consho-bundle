<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
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
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\PaymentRefundBackendController;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlerInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethod;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundResult;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Payment\FakeRefundingHandler;

/**
 * Block Zahlung Mollie, 4.4: "Erstatten", "Erneut auszahlen", "Von Hand
 * erledigt" — real template, real RefundPayout.
 */
final class PaymentRefundBackendControllerTest extends TestCase
{
    private Connection $connection;

    private FakeRefundingHandler $card;

    /** @var list<array{string, string}> */
    private array $messages = [];

    /** @var list<int> */
    private array $recorded = [];

    /** @var list<mixed> what the permission checks were asked about */
    private array $subjects = [];

    /** @var (\Closure(): void)|null what another operator does while the order is locked */
    private \Closure|null $onLock = null;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_payment', 'tl_consho_payment_attempt'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->card = new FakeRefundingHandler();

        $this->connection->insert('tl_consho_order', ['id' => 1, 'orderNumber' => 'WEB-1', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 10, 'pid' => 1, 'tokenHash' => 'h10', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment', ['id' => 5, 'pid' => 1, 'amount' => '30.0000', 'receivedAt' => 100, 'attempt' => 10, 'reference' => 'tr_1', 'method' => 'Karte']);
        // Typed by hand: no provider.
        $this->connection->insert('tl_consho_payment', ['id' => 6, 'pid' => 1, 'amount' => '15.0000', 'receivedAt' => 200, 'reference' => 'Bar', 'method' => 'Bar']);

        require __DIR__.'/../../../contao/languages/de/tl_consho_payment.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDasFormularIstMitDemNochOffenenBetragVorbelegt(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'refundOf' => 5, 'payoutState' => 'done']);

        $html = $this->controller($this->get('refundPayment'))->refund($this->dc(5));

        self::assertStringContainsString('name="consho_amount" id="ctrl_consho_amount" class="tl_text" inputmode="decimal" value="20.00"', $html);
        self::assertStringContainsString('value="Erstattung WEB-1"', $html);
        self::assertStringContainsString('WEB-1 — Visa, 30,00', $html);
        self::assertStringContainsString('name="REQUEST_TOKEN" value="tok"', $html);
    }

    public function testErstErstNachDerBestaetigungWirdZurueckgezahlt(): void
    {
        $this->card->refund = RefundResult::done('re_1');

        $html = $this->controller($this->post('refundPayment', ['consho_amount' => '12,50', 'consho_reference' => 'Kulanz']))->refund($this->dc(5));

        self::assertStringContainsString('12,50', $html);
        self::assertStringContainsString('über „Visa&quot; an den Kunden', $html);
        self::assertStringContainsString('name="consho_confirmed" value="1"', $html);
        self::assertSame([], $this->card->refunds, 'noch nichts ausgelöst');

        $this->redirected(fn () => $this->controller($this->post('refundPayment', ['consho_amount' => '12.5000', 'consho_reference' => 'Kulanz', 'consho_confirmed' => '1']))->refund($this->dc(5)));

        self::assertCount(1, $this->card->refunds);
        self::assertSame('12.5000', $this->card->refunds[0]->amount);
        self::assertSame('tr_1', $this->card->refunds[0]->paymentReference);
        self::assertSame(['addConfirmation', 'Der Zahlungsanbieter hat die Erstattung ausgeführt.'], $this->messages[0]);
        self::assertSame([1], $this->recorded, 'Folgen wie bei einer Zahlung von Hand');
        self::assertSame(['Kulanz', 'done'], array_values($this->connection->fetchAssociative('SELECT reference, payoutState FROM tl_consho_payment WHERE refundOf = 5') ?: []));
    }

    public function testZuVielOderNichtsWirdAbgelehnt(): void
    {
        foreach (['30,01', '0', '-5', 'abc', '1,005'] as $typed) {
            $html = $this->controller($this->post('refundPayment', ['consho_amount' => $typed, 'consho_reference' => '']))->refund($this->dc(5));
            self::assertStringNotContainsString('consho_confirmed', $html, $typed);
        }

        self::assertSame(['addError', "Bitte einen Betrag über 0 und höchstens 30,00\u{a0}€ eingeben."], $this->messages[0]);
        self::assertSame([], $this->card->refunds);
    }

    public function testEineZahlungOhneAnbieterHatKeinFormular(): void
    {
        $html = $this->controller($this->get('refundPayment'))->refund($this->dc(6));

        self::assertStringNotContainsString('<form', $html);
        self::assertStringContainsString('lässt sich nicht über den Zahlungsanbieter erstatten', $html);
    }

    public function testErstattenBrauchtDasRechtZahlungenAnzulegen(): void
    {
        $this->expectException(AccessDeniedHttpException::class);

        $this->controller($this->get('refundPayment'), false)->refund($this->dc(5));
    }

    /**
     * Refunding creates a payment row of this order; retry and manual change
     * the refund row itself — each checked against that record.
     */
    public function testDieRechteWerdenAmRichtigenDatensatzGeprueft(): void
    {
        $this->controller($this->get('refundPayment'))->refund($this->dc(5));
        self::assertInstanceOf(CreateAction::class, $this->subjects[0]);
        self::assertSame(1, $this->subjects[0]->getNew()['pid'] ?? null);

        $this->connection->insert('tl_consho_payment', ['id' => 7, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'refundOf' => 5, 'payoutState' => 'failed']);

        foreach (['retry', 'manual'] as $operation) {
            $this->subjects = [];
            $this->controller($this->get('refund'.ucfirst($operation)))->{$operation}($this->dc(7));

            $subject = $this->firstSubject();
            self::assertInstanceOf(UpdateAction::class, $subject, $operation);
            self::assertSame(7, (int) $subject->getCurrentId(), $operation);
            self::assertNull($subject->getNew(), $operation.': keine neuen Werte, die Zeile ist gesperrt');
        }
    }

    /**
     * Read through a method: PHPStan would keep the array empty after the
     * reset above, not knowing the controller fills it.
     */
    private function firstSubject(): mixed
    {
        return $this->subjects[0] ?? null;
    }

    public function testErneutAuszahlenUndVonHandErledigtBrauchenDasRecht(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 7, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'refundOf' => 5, 'payoutState' => 'failed']);

        foreach (['retry', 'manual'] as $operation) {
            try {
                $this->controller($this->post('refund'.ucfirst($operation), []), false)->{$operation}($this->dc(7));
                self::fail($operation.': verweigert erwartet');
            } catch (AccessDeniedHttpException) {
            }
        }

        self::assertSame('failed', $this->connection->fetchOne('SELECT payoutState FROM tl_consho_payment WHERE id = 7'));
        self::assertSame([], $this->card->refunds);
    }

    /**
     * "Erstatten" at THIS payment, even when a newer one could take it.
     */
    public function testErstattetWirdDieZahlungAnDerManKlickt(): void
    {
        $this->connection->insert('tl_consho_payment_attempt', ['id' => 11, 'pid' => 1, 'tokenHash' => 'h11', 'method' => 1, 'methodType' => 'card']);
        $this->connection->insert('tl_consho_payment', ['id' => 8, 'pid' => 1, 'amount' => '40.0000', 'receivedAt' => 300, 'attempt' => 11, 'reference' => 'tr_2', 'method' => 'Karte']);
        $this->card->refund = RefundResult::done('re_1');

        $this->redirected(fn () => $this->controller($this->post('refundPayment', ['consho_amount' => '10', 'consho_reference' => 'Kulanz', 'consho_confirmed' => '1']))->refund($this->dc(5)));

        self::assertSame('tr_1', $this->card->refunds[0]->paymentReference);
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT refundOf FROM tl_consho_payment WHERE amount < 0'));
    }

    /**
     * A colleague refunded the same payment between the form and the
     * confirmation: nothing is booked, and the operator is told.
     */
    public function testInzwischenErstattetBuchtNichts(): void
    {
        $this->onLock = function (): void {
            $this->connection->insert('tl_consho_payment', ['pid' => 1, 'amount' => '-30.0000', 'method' => 'refund', 'refundOf' => 5, 'payoutState' => 'done']);
        };

        $this->redirected(fn () => $this->controller($this->post('refundPayment', ['consho_amount' => '30', 'consho_reference' => 'Kulanz', 'consho_confirmed' => '1']))->refund($this->dc(5)));

        self::assertSame([], $this->card->refunds);
        self::assertSame(1, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_payment WHERE amount < 0'));
        self::assertSame(['addError', 'Die Erstattung hat sich inzwischen geändert. Bitte die Liste neu ansehen.'], $this->messages[0]);
        self::assertSame([], $this->recorded);
    }

    public function testErneutAuszahlenUndVonHandErledigt(): void
    {
        $this->connection->insert('tl_consho_payment', ['id' => 7, 'pid' => 1, 'amount' => '-10.0000', 'method' => 'refund', 'refundOf' => 5, 'payoutState' => 'failed']);
        $this->card->refund = RefundResult::failed('Guthaben zu niedrig');

        $html = $this->controller($this->get('refundRetry'))->retry($this->dc(7));
        self::assertStringContainsString("Die Erstattung über 10,00\u{a0}€ wird noch einmal bei „Visa&quot; angefordert", $html);

        $this->redirected(fn () => $this->controller($this->post('refundRetry', []))->retry($this->dc(7)));
        self::assertCount(1, $this->card->refunds);
        self::assertSame('addError', $this->messages[0][0]);

        $this->redirected(fn () => $this->controller($this->post('refundManual', []))->manual($this->dc(7)));
        self::assertSame('manual', $this->connection->fetchOne('SELECT payoutState FROM tl_consho_payment WHERE id = 7'));
        self::assertSame(['addConfirmation', 'Die Erstattung ist als erledigt vermerkt.'], $this->messages[1]);

        // Once more: nothing left to do.
        $this->redirected(fn () => $this->controller($this->post('refundRetry', []))->retry($this->dc(7)));
        self::assertSame(['addError', 'Die Erstattung hat sich inzwischen geändert. Bitte die Liste neu ansehen.'], $this->messages[2]);
        self::assertCount(1, $this->card->refunds);
    }

    private function get(string $key): Request
    {
        return Request::create('/contao?do=consho_order&table=tl_consho_payment&key='.$key.'&id=5');
    }

    /**
     * @param array<string, string> $data
     */
    private function post(string $key, array $data): Request
    {
        return Request::create('/contao?do=consho_order&table=tl_consho_payment&key='.$key.'&id=5', 'POST', $data);
    }

    private function redirected(\Closure $call): void
    {
        try {
            $call();
            self::fail('POST leitet um');
        } catch (RedirectResponseException) {
        }
    }

    private function controller(Request $request, bool $granted = true): PaymentRefundBackendController
    {
        $requests = new RequestStack();
        $requests->push($request);

        $subjects = &$this->subjects;
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(static function (mixed $attribute, mixed $subject = null) use ($granted, &$subjects): bool {
            $subjects[] = $subject;

            return $granted && ContaoCorePermissions::DC_PREFIX.'tl_consho_payment' === $attribute;
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
        $onLock = $this->onLock;
        $orders->method('lockOrder')->willReturnCallback(static function () use ($onLock): array {
            if (null !== $onLock) {
                $onLock();
            }

            return ['id' => '1'];
        });

        $recorded = $this->createMock(PaymentRecorded::class);
        $calls = &$this->recorded;
        $recorded->method('handle')->willReturnCallback(static function (int $orderId) use (&$calls): void {
            $calls[] = $orderId;
        });

        $payout = new RefundPayout($this->connection, $orders, $methods, new PaymentHandlers(new ServiceLocator([
            'card' => fn (): PaymentHandlerInterface => $this->card,
        ])));

        return new PaymentRefundBackendController($this->connection, $security, $framework, $requests, $twig, $payout, $methods, $recorded);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => $id,
            'table' => 'tl_consho_payment',
            default => null,
        });

        return $dc;
    }
}
