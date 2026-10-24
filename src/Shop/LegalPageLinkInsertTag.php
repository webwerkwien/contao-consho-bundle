<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Shop;

use Contao\CoreBundle\DependencyInjection\Attribute\AsInsertTag;
use Contao\CoreBundle\InsertTag\InsertTagResult;
use Contao\CoreBundle\InsertTag\OutputType;
use Contao\CoreBundle\InsertTag\ResolvedInsertTag;
use Contao\CoreBundle\Routing\PageFinder;
use Contao\StringUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;

/**
 * The links to the pages the law wants reachable from every page — for the
 * footer or any text:
 *
 *   {{consho_withdrawal_link}}            <a href="…">Vertrag widerrufen</a> (WA, I1)
 *   {{consho_cancellation_link}}          <a href="…">Verträge hier kündigen</a> (Abo-7)
 *   {{consho_…_link::<text>}}             with a text of one's own
 *   {{consho_withdrawal_url}}, {{consho_cancellation_url}}   the address only
 *
 * The page is the start point's own setting — "Widerrufsformular"
 * (consho_withdrawalFormPage) or "Seite ‚Verträge hier kündigen‘"
 * (consho_cancellationPage) — the one the start page's findings check, not a
 * second setting. The default text is the label the law sets (§ 356a BGB,
 * § 13a FAGG; § 312k Abs. 2 BGB), from the same language key as the form's
 * button, in the page's language.
 *
 * Empty outside a shop, without the page, or for a page that cannot be routed
 * (unpublished, deleted) — never a link that leads nowhere.
 */
final class LegalPageLinkInsertTag
{
    /** Tag prefix => [start point field, language key of the default text]. */
    private const PAGES = [
        'consho_withdrawal' => ['consho_withdrawalFormPage', 'CONSHO.withdrawal.button'],
        'consho_cancellation' => ['consho_cancellationPage', 'CONSHO.cancellation.button'],
    ];

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
    #[AsInsertTag('consho_cancellation_link')]
    #[AsInsertTag('consho_cancellation_url')]
    public function __invoke(ResolvedInsertTag $insertTag): InsertTagResult
    {
        $name = $insertTag->getName();
        $url = str_ends_with($name, '_url');
        [$field, $textKey] = self::PAGES[substr($name, 0, (int) strrpos($name, '_'))];
        $page = $this->pages->getCurrentPage();
        $root = null === $page ? null : $this->shops->rootOf($page);

        if (null === $page || null === $root || $this->shops->shopIdOfPage($root) < 1) {
            return new InsertTagResult('', $url ? OutputType::url : OutputType::html);
        }

        $targetId = (int) ($root->row()[$field] ?? 0);

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
            $text = $this->translator->trans($textKey, [], 'contao_default', '' !== $language ? $language : null);
        }

        return new InsertTagResult(
            \sprintf('<a href="%s">%s</a>', StringUtil::specialchars($address), StringUtil::specialchars($text)),
            OutputType::html,
            null,
            $tags,
        );
    }
}
