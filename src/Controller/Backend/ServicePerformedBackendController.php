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
use Webwerkwien\ContaoConshoBundle\Order\ServicePerformed;

/**
 * "Leistung erbracht" at an order line (Dienstleistung, DL3).
 *
 * POST with the request token, like "Als versandt melden" (D-1): it may end
 * the customer's right of withdrawal, and no link an editor opens may do
 * that. Checked as an update of the ORDER, which it changes.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class ServicePerformedBackendController
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
        private readonly ServicePerformed $performed,
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
            'SELECT o.* FROM tl_consho_order o INNER JOIN tl_consho_order_item i ON i.pid = o.id WHERE i.id = ?',
            [$id],
        ) : false;

        if (false === $order) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $order))) {
            throw new AccessDeniedHttpException();
        }

        $outcome = $this->performed->report($id, time());
        $lang = $GLOBALS['TL_LANG']['tl_consho_order_item']['performedResult'] ?? [];
        $message = $this->framework->getAdapter(Message::class);
        $name = StringUtil::specialchars((string) $this->connection->fetchOne('SELECT productName FROM tl_consho_order_item WHERE id = ?', [$id]));

        ServicePerformed::PERFORMED === $outcome
            ? $message->addConfirmation(\sprintf($lang[$outcome] ?? '%s', $name))
            : $message->addError($lang[$outcome] ?? $outcome);

        throw new RedirectResponseException($this->framework->getAdapter(System::class)->getReferer());
    }
}
