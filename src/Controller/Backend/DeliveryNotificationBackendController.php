<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Exception\InvalidRequestTokenException;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\UpdateAction;
use Contao\DataContainer;
use Contao\Message;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReported;

/**
 * "Benachrichtigung erneut senden" at a delivery.
 *
 * The shape of OrderConfirmationBackendController: a POST operation, a GET
 * only with a valid `rt`, a message, back to where the button was. The
 * permission is the update permission on the ORDER, as for "Als versandt
 * melden" (DeliveryReportBackendController): a reported delivery is locked,
 * so its own update permission is never granted.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class DeliveryNotificationBackendController
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Security $security,
        private readonly ContaoFramework $framework,
        private readonly RequestStack $requests,
        #[Autowire(service: 'contao.csrf.token_manager')]
        private readonly ContaoCsrfTokenManager $csrf,
        #[Autowire(param: 'contao.csrf_token_name')]
        private readonly string $csrfTokenName,
        private readonly DeliveryReported $reported,
    ) {
    }

    public function resend(DataContainer $dc): never
    {
        $request = $this->requests->getCurrentRequest();

        if ((null === $request || $request->isMethodSafe()) && !$this->csrf->isTokenValid(new CsrfToken($this->csrfTokenName, (string) $request?->query->get('rt')))) {
            throw new InvalidRequestTokenException('Invalid CSRF token. Please reload the page and try again.');
        }

        $id = (int) $dc->id;
        $order = $id > 0 ? $this->connection->fetchAssociative('SELECT o.* FROM tl_consho_order o JOIN tl_consho_delivery d ON d.pid = o.id WHERE d.id = ?', [$id]) : false;

        if (false === $order) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            throw new AccessDeniedHttpException();
        }

        $message = $this->framework->getAdapter(Message::class);
        $number = StringUtil::specialchars((string) $order['orderNumber']);
        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery'] ?? [];

        // No address any more, and the mail's link would issue a new token
        // for what the anonymisation closed (D-5) — as at the confirmation.
        if (null !== ($order['anonymizedAt'] ?? null)) {
            $message->addError(\sprintf($lang['resendNotificationAnonymized'] ?? '%s', $number));

            throw new RedirectResponseException($this->framework->getAdapter(System::class)->getReferer());
        }

        // Contao prints messages unescaped; the address is what the customer
        // typed in the checkout.
        match ($this->reported->resendNotification($id, time())) {
            true => $message->addConfirmation(\sprintf($lang['resendNotificationSent'] ?? '%s %s', $number, StringUtil::specialchars((string) $order['email']))),
            false => $message->addError(\sprintf($lang['resendNotificationFailed'] ?? '%s', $number)),
            null => $message->addInfo(\sprintf($lang['resendNotificationNothing'] ?? '%s', $number)),
        };

        throw new RedirectResponseException($this->framework->getAdapter(System::class)->getReferer());
    }
}
