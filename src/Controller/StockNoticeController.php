<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\FrontendUser;
use Contao\PageModel;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Customer\GuestOrderConfirmation;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\StockNotices;

/**
 * "Benachrichtigen, wenn wieder da" and the confirmation from its mail
 * (Block G, G12, G15) — one POST route for the three buttons on the product
 * page: subscribe, unsubscribe, confirm.
 *
 * Built like the add route (AddToCartController): the product page may sit in
 * the shared cache, so no request token; the Origin header is checked
 * instead. The member comes from the session, never from the form — a wish
 * or a confirmation for another account cannot be posted.
 *
 * The answer is always the product page again, with the outcome in
 * ?consho_stock_notice= (StockNoticeResult), looked up as an enumeration
 * there — never echoed.
 */
#[AsController]
final class StockNoticeController
{
    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ShopResolver $shops,
        private readonly Security $security,
        private readonly StockNotices $notices,
        private readonly GuestOrderConfirmation $confirmations,
        private readonly ProductUrlGenerator $urls,
    ) {
    }

    #[Route(
        path: '/_consho/stock/notice',
        name: 'consho_stock_notice',
        defaults: ['_scope' => 'frontend', '_token_check' => false],
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        if (!AddToCartController::originMatches($request)) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $this->framework->initialize();

        $page = $this->framework->getAdapter(PageModel::class)->findById((int) $request->request->getString('page'));

        if (!$page instanceof PageModel) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        // rootId is filled in by walking the tree (AddToCartController).
        $page->loadDetails();
        $root = $this->shops->rootOf($page);
        $shop = $root instanceof PageModel ? $this->shops->resolveById($this->shops->shopIdOfPage($root)) : null;

        if (!$root instanceof PageModel || !$shop instanceof ShopModel) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        $article = (int) $request->request->getString('article');
        $main = (int) $request->request->getString('main');
        $user = $this->security->getUser();
        $member = $user instanceof FrontendUser ? $user : null;
        $now = time();

        $result = match (true) {
            null === $member => StockNoticeResult::Login,
            'confirm' === $request->request->getString('consho_action') => StockNoticeResult::from(
                $this->confirmations->confirm($request->request->getString('token'), (int) $member->id, (string) $member->email, $now, ConfirmationPurpose::StockNotice),
            ),
            'unsubscribe' === $request->request->getString('consho_action') => $this->unsubscribe($article, (int) $member->id),
            default => $this->notices->subscribe($article, (int) $member->id, (int) $shop->id, (int) $root->id, (string) $page->language, $now)
                ? StockNoticeResult::Subscribed
                : StockNoticeResult::Available,
        };

        try {
            $url = $this->urls->generate($main > 0 ? $main : $article, (int) $root->id, UrlGeneratorInterface::ABSOLUTE_URL, $main > 0 && $article !== $main ? $article : 0);
        } catch (ProductPageMissing) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        return new RedirectResponse($url.(str_contains($url, '?') ? '&' : '?').http_build_query([StockNoticeResult::PARAM => $result->value]), Response::HTTP_SEE_OTHER);
    }

    private function unsubscribe(int $article, int $memberId): StockNoticeResult
    {
        $this->notices->unsubscribe($article, $memberId);

        return StockNoticeResult::Unsubscribed;
    }
}
