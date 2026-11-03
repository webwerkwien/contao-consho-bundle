<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

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
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Order\PaymentRecorded;
use Webwerkwien\ContaoConshoBundle\Payment\Aftermath\PaymentClaim;
use Webwerkwien\ContaoConshoBundle\Payment\Refund\RefundPayout;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * "Nachträge" at an order (spec 9): what the payment provider reported after
 * the payment — chargebacks and refunds made at the provider — and what the
 * operator does about them.
 *
 * - a foreign refund: "In Consho erfassen" books it as a refund row,
 *   "Einer Zeile zuordnen" ties it to a refund row of the same amount (P4)
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
final class ProviderEventBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_provider_events.html.twig';

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
            } else {
                $this->act($id, $request->request->getString('consho_action'), $request->request->getInt('consho_event'), $request->request->getInt('consho_row'));
            }

            throw new RedirectResponseException($request->getUri());
        }

        $outstanding = $this->claims->outstanding($id);

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'number' => (string) $order['orderNumber'],
            'claim' => null === $outstanding ? null : [
                'outstanding' => $this->money->format($outstanding, (string) $order['currency'], (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de')),
                'day' => PaymentClaim::defaultDay(time()),
            ],
            'events' => $this->events($order),
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

    private function record(int $orderId, int $eventId, int $now): string|null
    {
        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_payment', new CreateAction('tl_consho_payment', ['pid' => $orderId]))) {
            throw new AccessDeniedHttpException();
        }

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

        return array_map(function (array $event) use ($currency, $language, $format): array {
            $open = 'open' === $event['state'];
            $refund = 'refund' === $event['kind'];

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
                'canRecord' => $refund && $open,
                'rows' => $refund && $open ? array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'label' => trim(((int) $row['receivedAt'] > 0 ? date($format, (int) $row['receivedAt']).' — ' : '').(string) $row['reference']),
                ], $this->payout->assignableRows((int) $event['id'])) : [],
            ];
        }, $rows);
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
