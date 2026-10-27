<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;

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
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Subscription\PauseConfirmation;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPause;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionPauseRefused;

/**
 * "Pausieren" at a subscription in the back end (Abo pausieren, P101).
 *
 * Like "Testmail senden": GET renders a small form inside the back end frame,
 * POST acts and redirects back to it (POST, redirect, GET). The operator may
 * pause beyond the customer's allowance, in whole periods, up to a year —
 * a gym grants a longer pause for an illness —, and withdraw an announced
 * pause (P107). The customer gets "Pause bestätigt" either way (P110).
 *
 * Checked as access to the module "Abos": the table is
 * notEditable (P20), and Contao's DcaPermissionVoter denies EVERY update of
 * it — the admin's too. The mask stays read-only; this page is the action.
 */
#[Autoconfigure(public: true)]
final class SubscriptionPauseBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_subscription_pause.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly SubscriptionPause $pauses,
        private readonly PauseConfirmation $confirmation,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        // The id is a subscription's only in its own table (H-10):
        // `table=tl_consho_subscription_pause&key=…&id=5` is pause 5.
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
        $now = time();

        if (null !== $request && $request->isMethod('POST')) {
            $message = $this->framework->getAdapter(Message::class);

            if ('withdraw' === $request->request->getString('consho_pause_action')) {
                $this->pauses->withdraw($id, $now)
                    ? $message->addConfirmation((string) ($lang['pauseWithdrawn'] ?? 'Withdrawn.'))
                    : $message->addError((string) ($lang['pauseUnavailable'] ?? 'Not possible.'));
            } else {
                try {
                    $scheduled = $this->pauses->schedule($id, $request->request->getInt('consho_pause_months'), 'operator', $now);
                    $this->confirmation->send($scheduled);
                    $message->addConfirmation((string) ($lang['pauseScheduled'] ?? 'Paused.'));
                } catch (SubscriptionPauseRefused) {
                    $message->addError((string) ($lang['pauseUnavailable'] ?? 'Not possible.'));
                }
            }

            throw new RedirectResponseException($request->getUri());
        }

        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'number' => (string) $row['subscriptionNumber'],
            'from' => CancellationForm::day((int) $row['periodUntil'], $language),
            'options' => array_map(fn (int $months): array => [
                'months' => $months,
                'until' => CancellationForm::day($this->pauses->untilFor($row, $months), $language),
            ], $this->pauses->options($row, $now, true)),
            'announced' => SubscriptionPause::announced($row, $now)
                ? ['from' => CancellationForm::day((int) $row['pauseFrom'], $language), 'until' => CancellationForm::day((int) $row['pauseUntil'], $language), 'withdrawable' => SubscriptionPause::withdrawable($row, $now)]
                : null,
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
        ]);
    }
}
