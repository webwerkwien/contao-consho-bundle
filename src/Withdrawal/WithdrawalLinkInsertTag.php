<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

use Contao\CoreBundle\DependencyInjection\Attribute\AsInsertTag;
use Contao\CoreBundle\InsertTag\InsertTagResult;
use Contao\CoreBundle\InsertTag\OutputType;
use Contao\CoreBundle\InsertTag\ResolvedInsertTag;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\StringUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * The link to the withdrawal form of the site (WA, I1) — for the footer or
 * any text, so the function is reachable from every page.
 *
 *   {{consho_withdrawal_link}}         <a href="…">Vertrag widerrufen</a>
 *   {{consho_withdrawal_link::<text>}} with a text of one's own
 *   {{consho_withdrawal_url}}          the address only
 *
 * The page is the start point's "Widerrufsformular" (consho_withdrawalFormPage)
 * — the one the start page's finding checks, not a second setting. The
 * default text is the form's own label, „Vertrag widerrufen" (§ 356a BGB,
 * § 13a FAGG), in the page's language.
 *
 * Empty outside a shop, without the page, or for a page that cannot be routed
 * (unpublished, deleted) — never a link that leads nowhere.
 */
final class WithdrawalLinkInsertTag
{
    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ShopPageUrls $urls,
        #[Autowire(service: 'contao.routing.page_finder')]
        private readonly PageFinder $pages,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[AsInsertTag('consho_withdrawal_link')]
    #[AsInsertTag('consho_withdrawal_url')]
    public function __invoke(ResolvedInsertTag $insertTag): InsertTagResult
    {
        $url = 'consho_withdrawal_url' === $insertTag->getName();
        $page = $this->pages->getCurrentPage();
        $root = null === $page ? null : $this->shops->rootOf($page);

        if (null === $page || null === $root || $this->shops->shopIdOfPage($root) < 1) {
            return new InsertTagResult('', $url ? OutputType::url : OutputType::html);
        }

        $targetId = (int) ($root->row()['consho_withdrawalFormPage'] ?? 0);

        // The start point says which page, the page says its address:
        // changing either has to reach every page that shows the link.
        $tags = ['contao.db.tl_page.'.$root->id];

        if ($targetId > 0) {
            $tags[] = 'contao.db.tl_page.'.$targetId;
        }

        $address = $this->urls->ofPageId($targetId);

        if (null === $address) {
            return new InsertTagResult('', $url ? OutputType::url : OutputType::html, null, $tags);
        }

        if ($url) {
            return new InsertTagResult($address, OutputType::url, null, $tags);
        }

        $text = trim((string) ($insertTag->getParameters()->get(0) ?? ''));

        if ('' === $text) {
            $language = (string) $page->language;
            $text = $this->translator->trans('CONSHO.withdrawal.button', [], 'contao_default', '' !== $language ? $language : null);
        }

        return new InsertTagResult(
            \sprintf('<a href="%s">%s</a>', StringUtil::specialchars($address), StringUtil::specialchars($text)),
            OutputType::html,
            null,
            $tags,
        );
    }
}
