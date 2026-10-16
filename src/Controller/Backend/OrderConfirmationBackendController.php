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
use Contao\PageModel;
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
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationInterface;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;

/**
 * "Erneut senden" at an order (C-9, spec 7.6).
 *
 * ── Why POST, and the token check for GET ────────────────────────────────
 *
 * The same shape as the core's own resend (tl_opt_in, `resendToken`): the
 * operation is declared with `'method' => 'POST'`, so the button is a form
 * carrying the request token, and a GET is only accepted with a valid `rt`.
 * A plain link would let any page an editor opens trigger a mail to a
 * customer.
 *
 * ── A new view token ─────────────────────────────────────────────────────
 *
 * The token of the purchase exists in plaintext only in that request and
 * cannot be read back (PlacedOrder). The resend issues a fresh one for the
 * same order, as the double click does; the old link keeps working.
 *
 * No operator copy: the operator is the one pressing the button.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class OrderConfirmationBackendController
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
        private readonly OrderConfirmationInterface $confirmations,
        private readonly OrderConfirmationSourceInterface $source,
        private readonly TokenGenerator $tokens,
        private readonly ShopPageUrls $pageUrls,
    ) {
    }

    public function resend(DataContainer $dc): never
    {
        $request = $this->requests->getCurrentRequest();

        if ((null === $request || $request->isMethodSafe()) && !$this->csrf->isTokenValid(new CsrfToken($this->csrfTokenName, (string) $request?->query->get('rt')))) {
            throw new InvalidRequestTokenException('Invalid CSRF token. Please reload the page and try again.');
        }

        $id = (int) $dc->id;
        $row = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [$id]) : false;

        if (false === $row) {
            throw new NotFoundHttpException();
        }

        // An update: the send writes confirmationSentAt.
        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_order', new UpdateAction('tl_consho_order', $row))) {
            throw new AccessDeniedHttpException();
        }

        // Contao prints messages unescaped; the address is what the customer
        // typed in the checkout (review 2026-09-25).
        $message = $this->framework->getAdapter(Message::class);
        $number = StringUtil::specialchars((string) $row['orderNumber']);
        $rootId = $this->source->rootOf((int) $row['shop'], (string) $row['language']);
        $root = null === $rootId ? null : $this->framework->getAdapter(PageModel::class)->findById($rootId);
        $orderPage = $root instanceof PageModel ? $this->pageUrls->orderOfRoot($root) : null;

        if (null !== ($row['anonymizedAt'] ?? null)) {
            // D-5: no address any more, and a new token would reopen what the
            // anonymisation closed (Fable review D-5).
            $message->addError(\sprintf($GLOBALS['TL_LANG']['tl_consho_order']['resendAnonymized'] ?? '%s', $number));
        } elseif (null === $rootId || null === $orderPage) {
            // Without an order page the mail would link nowhere — the same
            // reason the checkout refuses to sell without one.
            $message->addError(\sprintf($GLOBALS['TL_LANG']['tl_consho_order']['resendNoOrderPage'] ?? '%s', $number));
        } else {
            $viewUrl = \sprintf('%s?consho_order=%s', $orderPage, $this->tokens->issue($id, TokenPurpose::ViewOrder, null, true));

            if ($this->confirmations->send($id, $rootId, $viewUrl, time(), false)) {
                $message->addConfirmation(\sprintf($GLOBALS['TL_LANG']['tl_consho_order']['resendSent'] ?? '%s', $number, StringUtil::specialchars((string) $row['email'])));
            } else {
                $message->addError(\sprintf($GLOBALS['TL_LANG']['tl_consho_order']['resendFailed'] ?? '%s', $number));
            }
        }

        // Back to where the button was, list filters and page included — what
        // Controller::redirect() does, thrown directly.
        throw new RedirectResponseException($this->framework->getAdapter(System::class)->getReferer());
    }
}
