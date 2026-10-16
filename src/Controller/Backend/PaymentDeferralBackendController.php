<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\BackendUser;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
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
use Webwerkwien\ContaoConshoBundle\Order\PaymentDeferral;

/**
 * "Zahlungsziel verschieben" at an order (E8).
 *
 * The shape of SubscriptionPauseBackendController: GET renders a small form
 * inside the back end frame, POST acts and redirects back (POST, redirect,
 * GET). The update permission on the order, like "Erneut senden" — the
 * action writes it.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class PaymentDeferralBackendController
{
    public const TEMPLATE = '@Contao/backend/consho_payment_deferral.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        private readonly Environment $twig,
        private readonly PaymentDeferral $deferral,
    ) {
    }

    public function __invoke(DataContainer $dc): string
    {
        $id = 'tl_consho_order' === $dc->table ? (int) $dc->id : 0;
        $row = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]) : false;

        if (false === $row) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $row))) {
            throw new AccessDeniedHttpException();
        }

        $request = $this->requests->getCurrentRequest();
        $lang = $GLOBALS['TL_LANG']['tl_consho_order'] ?? [];

        if (null !== $request && $request->isMethod('POST')) {
            $user = $this->security->getUser();
            $refused = $this->deferral->defer($id, $request->request->getString('consho_due_date'), time(), $user instanceof BackendUser ? (string) $user->username : '');
            $message = $this->framework->getAdapter(Message::class);

            null === $refused
                ? $message->addConfirmation((string) ($lang['deferDone'] ?? 'Moved.'))
                : $message->addError((string) ($lang['deferRefused'][$refused] ?? $refused));

            throw new RedirectResponseException($request->getUri());
        }

        $dueAt = (int) $row['paymentDueAt'];

        return $this->twig->render(self::TEMPLATE, [
            'messages' => (string) $this->framework->getAdapter(Message::class)->generate(),
            'number' => (string) $row['orderNumber'],
            'deferrable' => $this->deferral->isDeferrable($row),
            'current' => $dueAt > 0 ? date('Y-m-d', $dueAt) : '',
            'currentLabel' => $dueAt > 0 ? date((string) ($GLOBALS['TL_CONFIG']['dateFormat'] ?? 'd.m.Y'), $dueAt) : '',
            // The calendar's next day, not +86400 s: on the day of a clock
            // change that would be one day off (review E8, L5).
            'min' => $dueAt > 0 ? (new \DateTimeImmutable('@'.$dueAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify('+1 day')->format('Y-m-d') : '',
            'action' => null === $request ? '' : $request->getUri(),
            'back' => $this->framework->getAdapter(System::class)->getReferer(),
            'lang' => $lang,
        ]);
    }
}
