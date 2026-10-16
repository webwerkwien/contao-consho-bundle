<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\OrderController;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;

/**
 * The link to an order's page, with a fresh token — for the mails after a
 * payment or a delivery (D-1).
 *
 * A fresh token each time, like the resend and the payment return: a token
 * is stored as a hash and cannot be read back. The token confirms the
 * address — it travels by mail, so a click on it proves the mailbox.
 */
class OrderPageLink
{
    public function __construct(
        private readonly OrderConfirmationSourceInterface $source,
        private readonly ShopPageUrls $pageUrls,
        private readonly TokenGenerator $tokens,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * @param array<string, mixed> $order a row of tl_consho_order
     *
     * @return string|null null where the shop has no order page — the mail
     *                     would link nowhere
     */
    public function forMail(array $order): string|null
    {
        $this->framework->initialize();

        $rootId = $this->source->rootOf((int) ($order['shop'] ?? 0), (string) ($order['language'] ?? ''));
        $root = null === $rootId ? null : $this->framework->getAdapter(PageModel::class)->findById($rootId);
        $orderPage = $root instanceof PageModel ? $this->pageUrls->orderOfRoot($root) : null;

        if (null === $orderPage) {
            return null;
        }

        return \sprintf('%s?%s', $orderPage, http_build_query([
            OrderController::TOKEN_PARAM => $this->tokens->issue((int) $order['id'], TokenPurpose::ViewOrder, null, true),
        ]));
    }
}
