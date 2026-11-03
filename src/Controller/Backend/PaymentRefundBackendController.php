<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Message;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Intl\Currencies;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\ProviderEvent;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The refund operations at a payment row (block Zahlung Mollie, 4.4):
 *
 * - "Erstatten" at a received payment whose provider can pay back: amount
 *   and reference, then a confirmation naming both and the payment method
 *   (R5), then RefundPayout against that payment only. No mail, no
 *   document — the same as a negative row typed by hand; the balance has
 *   the same consequences (PaymentRecorded).
 * - "Erneut auszahlen" at a refund the provider refused.
 * - "Von Hand erledigt" at a refund that failed, hangs or is pending: the
 *   operator paid back by hand or saw it go through at the provider.
 *
 * Every page is a GET and every action a POST with Contao's request token,
 * as at "Zahlungsziel verschieben". The confirmation is a page, not a
 * browser dialog. Permission (R5): whoever may change the order's payments
 * — create a row for "Erstatten", update the row for the other two.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class PaymentRefundBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_payment_refund.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly RefundPayout $payout,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentRecorded $recorded,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    public function refund(DataContainer $dc): string
    {
        [$row, $order] = $this->load($dc);

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_payment', new CreateAction('tl_consho_payment', ['pid' => (int) $order['id']]))) {
            throw new AccessDeniedHttpException();
        }

        $lang = $this->lang();
        $request = $this->requests->getCurrentRequest();
        $paymentId = (int) $row['id'];
        $orderId = (int) $order['id'];
        $refundable = $this->payout->refundable($orderId, $paymentId)[$paymentId] ?? null;
        $vars = $this->vars('refund', $row, $order);

        if (null === $refundable) {
            return $this->render($vars + ['refundable' => null]);
        }

        $digits = $this->digits((string) $order['currency']);
        $vars += [
            'refundable' => (string) BigDecimal::of($refundable)->toScale($digits, RoundingMode::Down),
            'refundableLabel' => $this->money((string) $refundable, $order),
            'amount' => (string) BigDecimal::of($refundable)->toScale($digits, RoundingMode::Down),
            'reference' => \sprintf((string) ($lang['refundReferenceDefault'] ?? 'Refund %s'), (string) $order['orderNumber']),
        ];

        if (null === $request || !$request->isMethod('POST')) {
            return $this->render($vars);
        }

        $post = $request->request;
        $reference = trim($post->getString('consho_reference'));
        $amount = $this->amount($post->getString('consho_amount'), $refundable, $digits);
        $vars['reference'] = $reference;
        $vars['amount'] = $post->getString('consho_amount');

        if (null === $amount) {
            $this->framework->getAdapter(Message::class)->addError(\sprintf((string) ($lang['refundInvalidAmount'] ?? '%s'), $vars['refundableLabel']));

            return $this->render(['messages' => (string) $this->framework->getAdapter(Message::class)->generate()] + $vars);
        }

        // First POST: the confirmation (R5). Second POST: the refund.
        if (!$post->has('consho_confirmed')) {
            return $this->render(['step' => 'confirm', 'amount' => $amount, 'amountLabel' => $this->money($amount, $order)] + $vars);
        }

        $booked = $this->payout->refund($orderId, $amount, $reference, time(), $paymentId);

        if ([] === $booked) {
            // Refunded meanwhile by someone else: under the lock less was left
            // than the form allowed.
            $this->framework->getAdapter(Message::class)->addError((string) ($lang['refundStateChanged'] ?? 'The refund has changed meanwhile.'));

            throw new RedirectResponseException($vars['back']);
        }

        $this->recorded->handle($orderId, false, time());
        $this->report($booked);

        throw new RedirectResponseException($vars['back']);
    }

    public function retry(DataContainer $dc): string
    {
        return $this->act($dc, 'retry', fn (int $id): bool => $this->payout->retry($id, time()));
    }

    /**
     * With the provider's refunds to choose from where its module can list
     * them (spec 9, N3): the operator points at the refund made in the
     * dashboard, and its ID is stored. Without a choice — or without an
     * answer from the provider — the plain mark, as before.
     */
    public function manual(DataContainer $dc): string
    {
        return $this->act($dc, 'manual', function (int $id): bool {
            $chosen = trim($this->requests->getCurrentRequest()?->request->getString('consho_provider_refund') ?? '');

            if ('' === $chosen) {
                return $this->payout->markManual($id, time());
            }

            // Only one of those offered — asked again, the list may have changed.
            $references = array_map(static fn (ProviderEvent $event): string => $event->reference, $this->payout->providerRefunds($id) ?? []);

            return \in_array($chosen, $references, true) && $this->payout->assign($id, $chosen, time(), $this->userName());
        }, function (array $row): array {
            // A request the provider answered with its own ID needs no choice.
            $offered = '' === (string) ($row['payoutReference'] ?? '') ? $this->payout->providerRefunds((int) $row['id']) : null;

            return ['providerRefunds' => null === $offered ? null : array_map(fn (ProviderEvent $event): array => [
                'reference' => $event->reference,
                'state' => $event->refundState,
                'date' => $event->occurredAt > 0 ? date((string) ($GLOBALS['TL_CONFIG']['dateFormat'] ?? 'd.m.Y'), $event->occurredAt) : '',
            ], $offered)];
        });
    }

    /**
     * @param \Closure(int): bool                                   $action
     * @param (\Closure(array<string, mixed>): array<string, mixed>)|null $extra more for the page
     */
    private function act(DataContainer $dc, string $step, \Closure $action, \Closure|null $extra = null): string
    {
        [$row, $order] = $this->load($dc);

        // No new values: a refund row is locked (lockedAt), and an update that
        // names values would be refused by the locked-record voter for each.
        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_payment', new UpdateAction('tl_consho_payment', $row))) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->requests->getCurrentRequest();
        $vars = $this->vars($step, $row, $order) + ['amountLabel' => $this->money((string) BigDecimal::of((string) $row['amount'])->negated(), $order)];

        if (null === $request || !$request->isMethod('POST')) {
            return $this->render($vars + (null === $extra ? [] : $extra($row)));
        }

        $id = (int) $row['id'];
        $lang = $this->lang();
        $message = $this->framework->getAdapter(Message::class);

        if (!$action($id)) {
            // Someone else was quicker, or the row is no longer in that state.
            $message->addError((string) ($lang['refundStateChanged'] ?? 'The refund has changed meanwhile.'));
        } elseif ('retry' === $step) {
            $this->report([$id]);
        } else {
            $message->addConfirmation((string) ($lang['refundManualDone'] ?? 'Marked as done.'));
        }

        throw new RedirectResponseException($vars['back']);
    }

    /**
     * What the providers answered, per booked row.
     *
     * @param list<int> $rowIds
     */
    private function report(array $rowIds): void
    {
        $lang = $this->lang();
        $message = $this->framework->getAdapter(Message::class);

        foreach ($rowIds as $rowId) {
            $state = (string) $this->connection->fetchOne('SELECT payoutState FROM tl_consho_payment WHERE id = ?', [$rowId]);
            $text = (string) ($lang['refundResult'][$state] ?? $state);

            'failed' === $state ? $message->addError($text) : $message->addConfirmation($text);
        }
    }

    /**
     * @return array{array<string, mixed>, array<string, mixed>} the payment row and its order
     */
    private function load(DataContainer $dc): array
    {
        $id = 'tl_consho_payment' === $dc->table ? (int) $dc->id : 0;
        $row = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_payment WHERE id = ?', [$id]) : false;
        $order = false === $row ? false : $this->connection->fetchAssociative('SELECT id, orderNumber, currency FROM tl_consho_order WHERE id = ?', [(int) $row['pid']]);

        if (false === $row || false === $order) {
            throw new NotFoundHttpException();
        }

        return [$row, $order];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $order
     *
     * @return array<string, mixed>
     */
    private function vars(string $step, array $row, array $order): array
    {
        $request = $this->requests->getCurrentRequest();

        return [
            'step' => $step,
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'number' => (string) $order['orderNumber'],
            'method' => $this->methodTitle($row),
            'paymentLabel' => $this->money((string) BigDecimal::of((string) $row['amount'])->abs(), $order),
            'paymentReference' => (string) $row['reference'],
            'payoutReference' => (string) ($row['payoutReference'] ?? ''),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => (string) $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $this->lang(),
        ];
    }

    /**
     * The payment method's title — of the row itself, or of the payment a
     * refund row pays back.
     *
     * @param array<string, mixed> $row
     */
    private function methodTitle(array $row): string
    {
        $paymentId = (int) ($row['refundOf'] ?? 0) > 0 ? (int) $row['refundOf'] : (int) $row['id'];
        $methodId = $this->connection->fetchOne(
            'SELECT a.method FROM tl_consho_payment p JOIN tl_consho_payment_attempt a ON a.id = p.attempt WHERE p.id = ?',
            [$paymentId],
        );

        return false === $methodId ? (string) $row['method'] : ($this->methods->byId((int) $methodId)->title ?? (string) $row['method']);
    }

    /**
     * The typed amount, positive and at most what is left; null otherwise.
     */
    private function amount(string $typed, string $refundable, int $digits): string|null
    {
        try {
            $amount = BigDecimal::of(str_replace(',', '.', trim($typed)));
        } catch (MathException) {
            return null;
        }

        // No fractions of a cent; trailing zeros are fine (the confirmation
        // posts the amount back at four places).
        if (!$amount->isPositive() || $amount->strippedOfTrailingZeros()->getScale() > $digits || $amount->isGreaterThan($refundable)) {
            return null;
        }

        return (string) $amount->toScale(4);
    }

    /**
     * @param array<string, mixed> $order
     */
    private function money(string $amount, array $order): string
    {
        return $this->money->format($amount, (string) $order['currency'], (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de'));
    }

    private function digits(string $currency): int
    {
        return Currencies::getFractionDigits('' !== $currency ? $currency : 'EUR');
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function render(array $vars): string
    {
        return $this->twig->render(self::TEMPLATE, $vars);
    }

    private function userName(): string
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (string) $user->username : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function lang(): array
    {
        return $GLOBALS['TL_LANG']['tl_consho_payment'] ?? [];
    }
}
