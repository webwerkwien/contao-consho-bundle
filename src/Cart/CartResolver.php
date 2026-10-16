<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Contao\FrontendUser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Finds the basket that belongs to this request — and never creates one.
 *
 * Creating is the add route's job alone (12): a visitor who only reads must
 * not leave a row and a cookie behind.
 *
 * A logged-in member beats the cookie. Their basket follows the account across
 * devices; the guest baskets of this browser are merged into it once, at login
 * (CartTakeoverListener), not on every request — one per shop, each into the
 * member basket of its own shop.
 *
 * Both are scoped to one shop (C-3b): the member account is shared across
 * shops, the basket is not — a shop is one invoicing entity.
 */
final class CartResolver
{
    public function __construct(
        private readonly DbalCartRepository $repository,
        private readonly TokenStorageInterface $tokens,
    ) {
    }

    /**
     * The shop comes from the caller, not from the page: all three callers
     * (add route, basket element, mini basket) have resolved it before asking
     * for the basket. Resolving it a second time here would be a second rule
     * for the same question — and the one in ShopResolver is the one that
     * counts.
     */
    public function resolve(Request $request, int $shopId): Cart|null
    {
        $memberId = $this->memberId();

        if ($memberId > 0) {
            return $this->repository->findForMember($memberId, $shopId);
        }

        $token = CartToken::fromRequest($request);

        return null === $token ? null : $this->repository->findByToken($token, $shopId);
    }

    public function memberId(): int
    {
        $user = $this->tokens->getToken()?->getUser();

        return $user instanceof FrontendUser ? (int) $user->id : 0;
    }
}
