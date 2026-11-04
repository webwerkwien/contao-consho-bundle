<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Brick\Math\BigDecimal;
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
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Order\MoneyFlows;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\PaymentClaim;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\PaymentActions;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\UnassignedRefunds;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * "Geldflüsse" at an order (block Geldflüsse, 3.6, J1): the balance on top,
 * below it every movement of money in time — payments, refunds with their way
 * and state, corrections, chargebacks, refunds made at the provider — with
 * what can be done about each (MoneyFlows reads them).
 *
 * It took over the page "Nachträge" (spec Zahlung Mollie, 9), whose actions
 * are the ones on the provider's events:
 *
 * - a foreign refund: "In Consho erfassen" books it as a refund row,
 *   "Einer Zeile zuordnen" ties it to a refund row of the same amount (P4);
 *   while a withdrawal of the order waits for its refund, it links there
 *   instead of recording (Geldflüsse H1, H2) — the withdrawal books it with
 *   its credit note, and recorded here it would leave the withdrawal nothing
 *   to refund
 * - a standing chargeback: "Zahlung einfordern" sets a term and asks the
 *   customer for the money (P3)
 *
 * The shape of PaymentDeferralBackendController: GET renders the page inside
 * the back end frame, POST acts and redirects back. Opening the page needs
 * the update permission on the order; recording a refund the permission to
 * create a payment row, assigning one to update that row — as at "Erstatten"
 * and "Von Hand erledigt".
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class MoneyFlowBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_money_flows.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly RefundPayout $payout,
        private readonly PaymentRecorded $recorded,
        private readonly PaymentClaim $claims,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
        // Links to the withdrawals (Geldflüsse H1); optional for tests built by hand.
        private readonly UrlGeneratorInterface|null $router = null,
        private readonly MoneyFlows|null $flows = null,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $id = 'tl_consho_order' === $dc->table ? (int) $dc->id : 0;
        $order = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]) : false;

        if (false === $order) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->requests->getCurrentRequest();
        $lang = $this->lang();

        if (null !== $request && $request->isMethod('POST')) {
            if ('claim' === $request->request->getString('consho_action')) {
                $this->claim($id, $request->request->getString('consho_day'));
            } elseif ('assignPayment' === $request->request->getString('consho_action')) {
                $this->assignPayment($id, $request->request->getInt('consho_row'), $request->request->getInt('consho_payment'));
            } else {
                $this->act($id, $request->request->getString('consho_action'), $request->request->getInt('consho_event'), $request->request->getInt('consho_row'));
            }

            throw new RedirectResponseException($request->getUri());
        }

        $outstanding = $this->claims->outstanding($id);
        $events = [];

        foreach ($this->events($order) as $event) {
            $events[$event['id']] = $event;
        }

        $flows = $this->flows?->of($id);
        $rows = [];

        foreach ($this->connection->fetchAllAssociative('SELECT * FROM tl_consho_payment WHERE pid = ?', [$id]) as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'number' => (string) $order['orderNumber'],
            // The payment list stays one click away: new payments, deleting one not locked yet.
            'payments' => $this->url(['do' => 'consho_order', 'table' => 'tl_consho_payment', 'id' => $id]),
            'claim' => null === $outstanding ? null : [
                'outstanding' => $this->money->format($outstanding, (string) $order['currency'], (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de')),
                'day' => PaymentClaim::defaultDay(time()),
            ],
            'balance' => null === $flows ? [] : $this->balance($flows['balance'], $flows['cancelled'], (string) $order['currency']),
            'movements' => null === $flows ? [] : $this->movements($flows['movements'], $events, $rows, (string) $order['currency']),
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
        ]);
    }

    private function act(int $orderId, string $action, int $eventId, int $rowId): void
    {
        $event = $this->connection->fetchAssociative('SELECT * FROM tl_consho_provider_event WHERE id = ? AND pid = ?', [$eventId, $orderId]);

        if (false === $event) {
            throw new NotFoundHttpException();
        }

        $lang = $this->lang();
        $message = $this->framework->getAdapter(Message::class);
        $now = time();

        $refused = match ($action) {
            'record' => $this->record($orderId, $eventId, $now),
            'assign' => $this->assign($eventId, $rowId, $now),
            default => throw new NotFoundHttpException(),
        };

        if (null !== $refused) {
            $message->addError((string) ($lang['refused'][$refused] ?? $refused));

            return;
        }

        $message->addConfirmation((string) ($lang['done'][$action] ?? 'OK'));
    }

    /**
     * "Zahlung einfordern" (P3): the order's update permission, checked for
     * the page, is the one it needs — it sets the order's term.
     */
    private function claim(int $orderId, string $day): void
    {
        $lang = $this->lang();
        $message = $this->framework->getAdapter(Message::class);
        $refused = $this->claims->claim($orderId, $day, time(), $this->userName());

        match ($refused) {
            null => $message->addConfirmation((string) ($lang['done']['claim'] ?? 'OK')),
            // The term is set; the mail is what failed.
            'mailNotSent' => $message->addInfo((string) ($lang['refused']['mailNotSent'] ?? $refused)),
            default => $message->addError((string) ($lang['refused'][$refused] ?? $refused)),
        };
    }

    /**
     * "Zahlung zuordnen" (Geldflüsse J3): ties a refund row without its
     * payment to one. It changes the row, so it needs the permission to
     * update it, as "Einer Zeile zuordnen" does.
     */
    private function assignPayment(int $orderId, int $rowId, int $paymentId): void
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_payment WHERE id = ? AND pid = ?', [$rowId, $orderId]);

        if (false === $row) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_payment', new UpdateAction('tl_consho_payment', $row))) {
            throw new AccessDeniedHttpException();
        }

        $lang = $this->lang();
        $message = $this->framework->getAdapter(Message::class);

        if (!$this->payout->assignToPayment($orderId, $rowId, $paymentId)) {
            $message->addError((string) ($lang['refused']['paymentNotAssignable'] ?? 'paymentNotAssignable'));

            return;
        }

        // No sum changes, so neither does the order's payment state: nothing to recompute.
        $message->addConfirmation((string) ($lang['done']['assignPayment'] ?? 'OK'));
    }

    private function record(int $orderId, int $eventId, int $now): string|null
    {
        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_payment', new CreateAction('tl_consho_payment', ['pid' => $orderId]))) {
            throw new AccessDeniedHttpException();
        }

        // Geldflüsse H2 (a withdrawal waiting for its refund) is checked
        // there, under the order's lock.
        $refused = $this->payout->recordForeign($eventId, $now, $this->userName());

        if (null === $refused) {
            // The balance changed, as after "Erstatten".
            $this->recorded->handle($orderId, false, $now);
        }

        return $refused;
    }

    private function assign(int $eventId, int $rowId, int $now): string|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_payment WHERE id = ?', [$rowId]);

        if (false === $row) {
            return 'rowNotAssignable';
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_payment', new UpdateAction('tl_consho_payment', $row))) {
            throw new AccessDeniedHttpException();
        }

        return $this->payout->assignForeign($eventId, $rowId, $now, $this->userName());
    }

    /**
     * Every event of the order, newest first, with what can be done.
     *
     * @param array<string, mixed> $order
     *
     * @return list<array<string, mixed>>
     */
    private function events(array $order): array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT * FROM tl_consho_provider_event WHERE pid = ? ORDER BY occurredAt DESC, id DESC', [(int) $order['id']]);
        $currency = (string) $order['currency'];
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $format = (string) ($GLOBALS['TL_CONFIG']['dateFormat'] ?? 'd.m.Y');
        $withdrawals = $this->openWithdrawals((int) $order['id']);
        $cancelled = null !== $order['cancelledAt'];

        return array_map(function (array $event) use ($currency, $language, $format, $withdrawals, $cancelled): array {
            $open = 'open' === $event['state'];
            $refund = 'refund' === $event['kind'];
            $foreign = $refund && $open;

            return [
                'id' => (int) $event['id'],
                'kind' => (string) $event['kind'],
                'state' => match (true) {
                    $refund => (string) $event['state'],
                    (int) $event['reversedAt'] > 0 => 'reversed',
                    'settled' === $event['state'] => 'settled',
                    default => 'standing',
                },
                'reference' => (string) $event['reference'],
                'reason' => (string) $event['reason'],
                'amount' => $this->money->format((string) $event['amount'], $currency, $language),
                'date' => (int) $event['occurredAt'] > 0 ? date($format, (int) $event['occurredAt']) : '',
                'resolvedBy' => (string) $event['resolvedBy'],
                // H2: not while a withdrawal waits for its refund.
                'canRecord' => $foreign && [] === $withdrawals,
                // H1: each such withdrawal, with this refund ticked there.
                'withdrawals' => $foreign ? array_map(fn (array $w): array => [
                    'number' => (string) $w['number'],
                    'url' => $this->url([
                        'do' => 'consho_withdrawal',
                        'key' => 'processWithdrawal',
                        'id' => (int) $w['id'],
                        WithdrawalBackendController::FOREIGN => (int) $event['id'],
                    ]),
                ], $withdrawals) : [],
                // H3: "Erfassen" is the cancellation's refund.
                'cancelled' => $foreign && $cancelled,
                'rows' => $foreign ? array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'label' => trim(((int) $row['receivedAt'] > 0 ? date($format, (int) $row['receivedAt']).' — ' : '').(string) $row['reference']),
                ], $this->payout->assignableRows((int) $event['id'])) : [],
            ];
        }, $rows);
    }

    /**
     * The balance as label and amount, the parts that are zero left out —
     * except the claim and what was received, which every order has.
     *
     * @param array<string, string> $balance
     *
     * @return list<array{label: string, value: string, strong: bool}>
     */
    private function balance(array $balance, bool $cancelled, string $currency): array
    {
        $labels = $this->lang()['balance'] ?? [];
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $lines = [];

        foreach (['claim', 'received', 'refunded', 'chargedBack', 'outside', 'outstanding', 'tooMuch'] as $key) {
            $value = BigDecimal::of($balance[$key]);

            if (!\in_array($key, ['claim', 'received'], true) && $value->isZero()) {
                continue;
            }

            $label = (string) ($labels[$key] ?? $key);

            // A cancelled order claims nothing; say why it is 0.
            if ('claim' === $key && $cancelled) {
                $label = (string) ($labels['claimCancelled'] ?? $label);
            }

            $lines[] = [
                'label' => $label,
                'value' => $this->money->format((string) $value, $currency, $language),
                'strong' => \in_array($key, ['outstanding', 'tooMuch'], true),
            ];
        }

        // Neither open nor too much: settled.
        if (!array_filter($lines, static fn (array $line): bool => $line['strong'])) {
            $lines[] = ['label' => (string) ($labels['settled'] ?? 'Ausgeglichen'), 'value' => '', 'strong' => true];
        }

        return $lines;
    }

    /**
     * The movements as the page shows them; a foreign refund carries the
     * actions of "Nachträge", a chargeback its event's state and reason.
     *
     * A payment row carries the operations of the payment list that apply
     * to it (PaymentActions decides for both).
     *
     * @param list<array<string, mixed>>       $movements
     * @param array<int, array<string, mixed>> $events    the event views by id
     * @param array<int, array<string, mixed>> $rows      the order's payment rows by id
     *
     * @return list<array<string, mixed>>
     */
    private function movements(array $movements, array $events, array $rows, string $currency): array
    {
        $this->framework->getAdapter(System::class)->loadLanguageFile('tl_consho_payment');

        $lang = $this->lang();
        $actions = new PaymentActions($this->payout);
        $payment = $GLOBALS['TL_LANG']['tl_consho_payment'] ?? [];
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $format = (string) ($GLOBALS['TL_CONFIG']['datimFormat'] ?? 'd.m.Y H:i');
        $dateFormat = (string) ($GLOBALS['TL_CONFIG']['dateFormat'] ?? 'd.m.Y');

        $byId = [];

        foreach ($movements as $movement) {
            if ('payment' === $movement['source']) {
                $byId[$movement['id']] = $movement;
            }
        }

        return array_map(function (array $m) use ($lang, $payment, $language, $format, $dateFormat, $byId, $events, $rows, $actions, $currency): array {
            $event = \is_array($m['event']) ? ($events[(int) $m['event']['id']] ?? null) : null;
            $kind = (string) $m['kind'];
            $details = [];

            if ('payment' === $kind) {
                $details[] = (string) $m['method'];
            }

            // A correction's reason, a payment's or refund's reference.
            $showsReference = '' !== $m['reference'] && 'foreignRefund' !== $kind && null === $m['withdrawal'];

            if ($showsReference) {
                $details[] = ('correction' === $kind ? ($lang['reason'] ?? 'Grund').': ' : '').$m['reference'];
            }

            // A refund recorded from the provider carries its ID in both fields: name it once.
            if ('' !== $m['payoutReference'] && !($showsReference && $m['payoutReference'] === $m['reference'])) {
                $details[] = \sprintf((string) ($lang['providerId'] ?? 'Anbieter: %s'), $m['payoutReference']);
            }

            if (null !== $event && '' !== $event['reason']) {
                $details[] = ($lang['reason'] ?? 'Grund').': '.$event['reason'];
            }

            $of = $byId[$m['refundOf']] ?? null;
            $unassigned = 'payment' === $m['source'] && isset($rows[$m['id']]) && UnassignedRefunds::is($rows[$m['id']]);

            return [
                'id' => (int) $m['id'],
                'kind' => $kind,
                'label' => (string) ($lang['movements'][$kind] ?? $kind),
                'date' => $m['at'] > 0 ? date($format, (int) $m['at']) : '',
                'amount' => $this->money->format((string) $m['amount'], $currency, $language),
                'negative' => BigDecimal::of((string) $m['amount'])->isNegative(),
                'details' => array_values(array_filter($details, static fn (string $d): bool => '' !== trim($d))),
                'state' => match (true) {
                    '' !== $m['payoutState'] => (string) ($payment['payoutStates'][$m['payoutState']] ?? $m['payoutState']),
                    null !== $event => (string) ($lang['states'][$event['state']] ?? $event['state']),
                    $m['locked'] => (string) ($lang['locked'] ?? 'gesperrt'),
                    default => '',
                },
                'of' => null === $of ? '' : \sprintf(
                    (string) ($lang['of'] ?? 'zu %s vom %s'),
                    $this->money->format((string) $of['amount'], $currency, $language),
                    $of['at'] > 0 ? date($dateFormat, (int) $of['at']) : '—',
                ),
                'withdrawal' => null === $m['withdrawal'] ? null : [
                    'number' => $m['withdrawal']['number'],
                    'url' => $this->url(['do' => 'consho_withdrawal', 'key' => 'processWithdrawal', 'id' => $m['withdrawal']['id']]),
                    'document' => $m['withdrawal']['document'] > 0 ? $this->url(['do' => 'consho_order', 'table' => 'tl_consho_document', 'key' => 'invoicePdf', 'id' => $m['withdrawal']['document']]) : '',
                ],
                'actions' => 'payment' === $m['source'] && isset($rows[$m['id']]) ? $this->rowActions($rows[$m['id']], $actions, $payment) : [],
                // J3: a refund without its payment, and the payments that can take it.
                'unassigned' => $unassigned,
                'assignTo' => $unassigned ? $this->assignOptions((int) $m['id'], $byId, $currency, $language, $dateFormat) : [],
                // The actions of "Nachträge" (H1–H3), only on an open foreign refund.
                'event' => 'foreignRefund' === $kind ? $event : null,
            ];
        }, $movements);
    }

    /**
     * The payments a refund without its payment can be tied to (J3), as
     * options: amount, date, what is left.
     *
     * @param array<int, array<string, mixed>> $byId the payment movements by id
     *
     * @return list<array{id: int, label: string}>
     */
    private function assignOptions(int $rowId, array $byId, string $currency, string $language, string $dateFormat): array
    {
        $lang = $this->lang();
        $options = [];

        foreach ($this->payout->assignablePayments($rowId) as $paymentId => $left) {
            $options[] = [
                'id' => $paymentId,
                'label' => \sprintf(
                    (string) ($lang['assignOption'] ?? '%s vom %s, offen %s'),
                    $this->money->format((string) ($byId[$paymentId]['amount'] ?? '0'), $currency, $language),
                    (int) ($byId[$paymentId]['at'] ?? 0) > 0 ? date($dateFormat, (int) $byId[$paymentId]['at']) : '—',
                    $this->money->format($left, $currency, $language),
                ),
            ];
        }

        return $options;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payment the language of tl_consho_payment
     *
     * @return list<array{label: string, title: string, url: string}>
     */
    private function rowActions(array $row, PaymentActions $actions, array $payment): array
    {
        $id = (int) $row['id'];

        return array_map(fn (string $operation): array => [
            'label' => (string) ($payment[$operation][0] ?? $operation),
            'title' => \sprintf((string) ($payment[$operation][1] ?? '%s'), $id),
            'url' => $this->url(['do' => 'consho_order', 'table' => 'tl_consho_payment', 'edit' === $operation ? 'act' : 'key' => $operation, 'id' => $id]),
        ], $actions->of($row));
    }

    /**
     * @param array<string, int|string> $query
     */
    private function url(array $query): string
    {
        return null === $this->router ? '' : $this->router->generate('contao_backend', $query);
    }

    /**
     * The order's withdrawals still to be refunded — received or accepted,
     * not refunded yet.
     *
     * @return list<array<string, mixed>>
     */
    private function openWithdrawals(int $orderId): array
    {
        return $this->connection->fetchAllAssociative(
            "SELECT id, number FROM tl_consho_withdrawal
              WHERE pid = ? AND state IN ('received', 'accepted') AND refundedAt IS NULL
              ORDER BY id",
            [$orderId],
        );
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
        return $GLOBALS['TL_LANG']['tl_consho_order']['followUpPage'] ?? [];
    }
}
