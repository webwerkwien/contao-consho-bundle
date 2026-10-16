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
 * "Als versandt melden" at a delivery (D-1).
 *
 * POST with the request token, like "Erneut senden" (C-9): the report sends
 * the customer a mail and may issue an invoice, and no page an editor opens
 * may trigger that with a plain link.
 *
 * Checked as an update of the ORDER: the report changes the order's balance,
 * status and documents, not just the delivery row.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class DeliveryReportBackendController
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

    public function report(DataContainer $dc): never
    {
        $request = $this->requests->getCurrentRequest();

        if ((null === $request || $request->isMethodSafe()) && !$this->csrf->isTokenValid(new CsrfToken($this->csrfTokenName, (string) $request?->query->get('rt')))) {
            throw new InvalidRequestTokenException('Invalid CSRF token. Please reload the page and try again.');
        }

        $id = (int) $dc->id;
        $order = $id > 0 ? $this->connection->fetchAssociative(
            'SELECT o.* FROM tl_consho_order o INNER JOIN tl_consho_delivery d ON d.pid = o.id WHERE d.id = ?',
            [$id],
        ) : false;

        if (false === $order) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            throw new AccessDeniedHttpException();
        }

        $result = $this->reported->report($id, time());
        $lang = $GLOBALS['TL_LANG']['tl_consho_delivery']['reportResult'] ?? [];
        $message = $this->framework->getAdapter(Message::class);
        $number = StringUtil::specialchars((string) $order['orderNumber']);

        match ($result->outcome) {
            'reported' => $result->mailSent
                ? $message->addConfirmation(\sprintf($lang['reported'] ?? '%s', $number))
                : $message->addInfo(\sprintf($lang['reportedNoMail'] ?? '%s', $number)),
            'tooMuch' => $message->addError(\sprintf($lang['tooMuch'] ?? '%s', StringUtil::specialchars(implode(', ', $result->tooMuch)))),
            'empty' => $message->addError($lang['empty'] ?? 'empty'),
            'alreadyReported' => $message->addInfo($lang['alreadyReported'] ?? 'alreadyReported'),
            default => throw new NotFoundHttpException(),
        };

        throw new RedirectResponseException($this->framework->getAdapter(System::class)->getReferer());
    }
}
