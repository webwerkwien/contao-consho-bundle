<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener;

use Contao\FrontendUser;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Contracts\Service\ResetInterface;
use Webwerkwien\ContaoConshoBundle\Cart\CartToken;
use Webwerkwien\ContaoConshoBundle\Cart\DbalCartRepository;

/**
 * Hands the guest baskets of this browser to the member who just logged in
 * (7.1, step 5) — plural since C-3b: one cookie carries a basket per shop,
 * and this request cannot say which shop is meant.
 *
 * Two listeners, one job: the login event has no guaranteed response to hang a
 * cookie on, so the take-over happens there and the cookie is cleared on the
 * response of the same request. The flag lives on the service and is reset
 * with it — one request, one login.
 *
 * Verified against Contao 5.7 / Symfony 7.4: ContaoLoginAuthenticator extends
 * AbstractAuthenticator and runs through AuthenticatorManager like every other
 * authenticator on contao_frontend, so a real form login does dispatch
 * LoginSuccessEvent — with the request that carries the cookie and the
 * passport's user, exactly what this listener reads (confirmed in
 * AuthenticatorManager::handleAuthenticationSuccess()). An interactive login
 * additionally fires InteractiveLoginEvent first, but LoginSuccessEvent is the
 * one that also covers a non-interactive re-authentication (e.g. remember-me),
 * so it is the safer hook to not miss a take-over on.
 */
class CartTakeoverListener implements ResetInterface
{
    private bool $clearCookie = false;

    public function __construct(private readonly DbalCartRepository $repository)
    {
    }

    /**
     * Under PHP-FPM the container dies with the request and the flag with it.
     * Under a worker runtime (FrankenPHP, RoadRunner) a shared service outlives
     * one request — and a flag set by a login whose response listener never ran
     * would then delete the basket cookie of the NEXT visitor served by that
     * worker. Symfony resets services between requests there; this is what it
     * calls. The same reason MethodInUseVoter carries it.
     */
    public function reset(): void
    {
        $this->clearCookie = false;
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof FrontendUser) {
            return;
        }

        $token = CartToken::fromRequest($event->getRequest());

        if (null === $token) {
            return;
        }

        // Every basket the cookie holds, not just one: since C-3b a token
        // carries one per shop, and this request belongs to whichever page
        // held the login form — it cannot say which shop is meant.
        $guests = $this->repository->findAllByToken($token);

        if ([] === $guests) {
            // A cookie without a basket: clear it anyway, it points nowhere.
            $this->clearCookie = true;

            return;
        }

        $now = time();

        foreach ($guests as $guest) {
            $this->repository->takeOver($guest, (int) $user->id, $now);
        }

        $this->clearCookie = true;
    }

    #[AsEventListener(event: KernelEvents::RESPONSE, priority: -1000)]
    public function onResponse(ResponseEvent $event): void
    {
        if (!$this->clearCookie || !$event->isMainRequest()) {
            return;
        }

        $this->clearCookie = false;

        $event->getResponse()->headers->setCookie(CartToken::clearCookie($event->getRequest()->isSecure()));
    }
}
