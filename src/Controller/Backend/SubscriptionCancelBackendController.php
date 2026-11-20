<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDeclaration;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationKind;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationOutcome;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationRecorder;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;
use Webwerkwien\ContaoConshoBundle\Cancellation\OperatorCancellationDates;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRenewal;

/**
 * "Kündigen" at a subscription in the back end (Abo-9, P128).
 *
 * The operator ends a subscription ordinarily, at the end of a paid stretch
 * (OperatorCancellationDates), with a reason the customer is told. The
 * earliest date that keeps the shop's lead time is preselected; a closer one
 * may be chosen, but only with a confirmation — the operator's notice is a
 * matter of his terms, which the page cannot judge.
 *
 * Like "Pausieren": GET renders the form inside the back end frame, a
 * recorded cancellation redirects back to it (POST, redirect, GET). An input
 * that is refused renders the form again with what was typed, so the reason
 * is not lost. Checked as access to the module "Abos", for the same reason as
 * the pause: the table is notEditable.
 */
#[Autoconfigure(public: true)]
final class SubscriptionCancelBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_subscription_cancel.html.twig';

    public const DEFAULT_LEAD_DAYS = 56;

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly OperatorCancellationDates $dates,
        private readonly CancellationRecorder $recorder,
        private readonly SubscriptionOffers $offers,
        private readonly SubscriptionRenewal $renewal,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        // The id is a subscription's only in its own table (H-10).
        $id = 'tl_consho_subscription' === $dc->table ? (int) $dc->id : 0;
        $row = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_subscription WHERE id = ?', [$id]) : false;

        if (false === $row) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::USER_CAN_ACCESS_MODULE, 'consho_subscription')) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_subscription'] ?? [];
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $now = time();
        $message = $this->framework->getAdapter(Message::class);

        $lead = $this->leadDays((int) $row['shop']);
        $dates = $this->open($row) ? $this->dates->of($row, $lead, $now) : [];
        $kept = array_values(array_filter($dates, static fn (array $date): bool => $date['kept']));
        $input = ['at' => $kept[0]['at'] ?? $dates[0]['at'] ?? 0, 'reason' => '', 'short' => false];

        if (null !== $request && $request->isMethod('POST')) {
            $input = [
                'at' => $request->request->getInt('consho_cancel_at'),
                'reason' => trim($request->request->getString('consho_cancel_reason')),
                'short' => '1' === $request->request->getString('consho_cancel_short'),
            ];
            $chosen = array_values(array_filter($dates, static fn (array $date): bool => $date['at'] === $input['at']))[0] ?? null;

            if (null === $chosen) {
                $message->addError((string) ($lang['cancelUnavailable'] ?? 'Not possible.'));
            } elseif ('' === $input['reason']) {
                $message->addError((string) ($lang['cancelReasonMissing'] ?? 'Reason missing.'));
            } elseif (!$chosen['kept'] && !$input['short']) {
                $message->addError((string) ($lang['cancelShortUnconfirmed'] ?? 'Not confirmed.'));
            } else {
                $result = $this->recorder->record(new CancellationDeclaration(
                    $id,
                    CancellationSource::Operator,
                    CancellationKind::Ordinary,
                    $this->userName(),
                    $chosen['at'],
                    $input['reason'],
                    $this->renewable($row, $chosen['at'], $now),
                ), $now);

                if (CancellationOutcome::Recorded === $result->outcome) {
                    $message->addConfirmation(\sprintf((string) ($lang['cancelRecorded'] ?? '%s: %s'), $result->number, CancellationForm::day((int) $result->effectiveAt, $language)));

                    if (!$result->confirmed) {
                        $message->addError((string) ($lang['cancelMailFailed'] ?? 'Mail failed.'));
                    }
                } else {
                    $message->addError((string) ($lang['cancelUnavailable'] ?? 'Not possible.'));
                }

                throw new RedirectResponseException($request->getUri());
            }
        }

        $endsAt = (int) ($row['endsAt'] ?? 0);

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $message->generate(),
            'number' => (string) $row['subscriptionNumber'],
            // An end in the past (ended) is "not possible", not "ends on".
            'endsAt' => $endsAt > $now ? CancellationForm::day($endsAt, $language) : null,
            'dates' => array_map(static fn (array $date): array => [
                'at' => $date['at'],
                'day' => CancellationForm::day($date['at'], $language),
                'kept' => $date['kept'],
            ], $dates),
            'input' => $input,
            'leadDays' => $lead,
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function open(array $row): bool
    {
        return 'ended' !== (string) $row['state'] && (int) ($row['endsAt'] ?? 0) < 1;
    }

    private function leadDays(int $shopId): int
    {
        $days = $this->connection->fetchOne('SELECT subscriptionOperatorDays FROM tl_consho_shop WHERE id = ?', [$shopId]);

        return false === $days || null === $days ? self::DEFAULT_LEAD_DAYS : (int) $days;
    }

    /**
     * Whether "Meine Abos" will offer "Lizenz verlängern" — only then does
     * the mail point to it. Both sides, as the button asks them: the
     * contract (SubscriptionRenewal::renewable(), as it will be with the
     * end set — not while resting, paused, overdue or already renewed) and
     * the plan, still on offer to this customer as they are today.
     *
     * @param array<string, mixed> $row
     */
    private function renewable(array $row, int $endsAt, int $now): bool
    {
        if (!$this->renewal->renewable(['endsAt' => $endsAt] + $row, $now)) {
            return false;
        }

        // GROUPS is a reserved word in MySQL 8.
        $groups = $this->connection->fetchOne(\sprintf('SELECT %s FROM tl_member WHERE id = ?', $this->connection->quoteSingleIdentifier('groups')), [(int) $row['member']]);

        if (false === $groups) {
            return false;
        }

        $ids = array_values(array_filter(
            array_map('intval', StringUtil::deserialize(\is_string($groups) ? $groups : '', true)),
            static fn (int $id): bool => $id > 0,
        ));

        return $this->offers->allows((int) $row['product'], (int) $row['rule'], $ids);
    }

    private function userName(): string
    {
        $user = $this->security->getUser();

        if ($user instanceof BackendUser && '' !== trim((string) $user->name)) {
            return (string) $user->name;
        }

        return null === $user ? '' : $user->getUserIdentifier();
    }
}
