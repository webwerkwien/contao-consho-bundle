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
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDecision;

/**
 * "Entscheidung erneut senden" at a cancellation (E10).
 *
 * The shape of OrderConfirmationBackendController: a POST operation, a GET
 * only with a valid `rt`, the update permission on the record (the send
 * writes decisionSentAt), a message, back to where the button was.
 *
 * Public, because Backend::getBackendModule() fetches it through
 * System::importStatic(), which only finds public services.
 */
#[Autoconfigure(public: true)]
final class DecisionResendBackendController
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
        private readonly CancellationDecision $decision,
    ) {
    }

    public function resend(DataContainer $dc): never
    {
        $request = $this->requests->getCurrentRequest();

        if ((null === $request || $request->isMethodSafe()) && !$this->csrf->isTokenValid(new CsrfToken($this->csrfTokenName, (string) $request?->query->get('rt')))) {
            throw new InvalidRequestTokenException('Invalid CSRF token. Please reload the page and try again.');
        }

        $id = (int) $dc->id;
        $row = $id > 0 ? $this->connection->fetchAssociative('SELECT * FROM tl_consho_cancellation WHERE id = ?', [$id]) : false;

        if (false === $row) {
            throw new NotFoundHttpException();
        }

        if (!$this->security->isGranted(ContaoCorePermissions::DC_PREFIX.'tl_consho_cancellation', new UpdateAction('tl_consho_cancellation', $row))) {
            throw new AccessDeniedHttpException();
        }

        $message = $this->framework->getAdapter(Message::class);
        $number = StringUtil::specialchars((string) $row['number']);
        $lang = $GLOBALS['TL_LANG']['tl_consho_cancellation'] ?? [];

        match ($this->decision->resend($id, time())) {
            true => $message->addConfirmation(\sprintf($lang['resendDecisionSent'] ?? '%s', $number)),
            false => $message->addError(\sprintf($lang['resendDecisionFailed'] ?? '%s', $number)),
            null => $message->addInfo(\sprintf($lang['resendDecisionNothing'] ?? '%s', $number)),
        };

        throw new RedirectResponseException($this->framework->getAdapter(System::class)->getReferer());
    }
}
