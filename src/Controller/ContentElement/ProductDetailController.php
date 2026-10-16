<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Brick\Math\BigDecimal;
use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Image\Studio\Figure;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\FrontendUser;
use Contao\PageModel;
use Contao\StringUtil;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Cart\CartNotice;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogCacheTags;
use Webwerkwien\ContaoConshoBundle\Catalog\CustomFieldDisplay;
use Webwerkwien\ContaoConshoBundle\Catalog\DbalCatalogSchedule;
use Webwerkwien\ContaoConshoBundle\Catalog\DurabilityLabelImages;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetail;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailAssembler;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRepository;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductDetailRow;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductSafetyResolver;
use Webwerkwien\ContaoConshoBundle\Catalog\Read\CatalogContext;
use Webwerkwien\ContaoConshoBundle\Model\ShopModel;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantAxisView;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantMatrix;
use Webwerkwien\ContaoConshoBundle\Catalog\VariantRepository;
use Webwerkwien\ContaoConshoBundle\Confirmation\ConfirmationPurpose;
use Webwerkwien\ContaoConshoBundle\Confirmation\EmailConfirmation;
use Webwerkwien\ContaoConshoBundle\Controller\Page\ProductPageController;
use Webwerkwien\ContaoConshoBundle\Controller\StockNoticeResult;
use Webwerkwien\ContaoConshoBundle\Customer\GuestOrderConfirmation;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DetailPartialOptionsListener;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\ArticlePriceResolver;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\OneTimeOffer;
use Webwerkwien\ContaoConshoBundle\Pricing\OneTimePrice;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudience;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceAudienceResolver;
use Webwerkwien\ContaoConshoBundle\Seo\ProductHead;
use Webwerkwien\ContaoConshoBundle\Seo\OfferDetails;
use Webwerkwien\ContaoConshoBundle\Seo\ProductJsonLd;
use Webwerkwien\ContaoConshoBundle\Seo\ProductPageMissing;
use Webwerkwien\ContaoConshoBundle\Seo\ProductUrlGenerator;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingRates;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Stock\ArticleAvailability;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockNotices;
use Webwerkwien\ContaoConshoBundle\Stock\StockState;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRule;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Tax\NotTaxableArticles;

/**
 * The product on a product page — images, texts in the page's language, the
 * price, the variant choice, the safety notices and the manufacturer block.
 *
 * It only puts together what other classes decide: ProductDetailRepository and
 * ProductDetailAssembler (texts, inheritance), VariantRepository and
 * VariantMatrix (which variant a link leads to), ArticlePriceResolver and
 * MoneyFormatter (price), ProductSafetyResolver (GPSR, no language fallback),
 * ProductUrlGenerator (every address), ProductHead (title, canonical) and
 * ProductJsonLd (schema.org markup, plan B-6).
 *
 * ── Where the product comes from ──────────────────────────────────────────
 *
 * ProductPageController resolves the address and puts the product id into the
 * request attribute _consho_product. Content elements run as a forward
 * sub-request, which duplicates the main request, attributes included.
 *
 * Without the attribute — the element on another page, in a layout section of
 * a page that is not a product page, a preview — it renders nothing and never
 * throws. Where a product was due and none exists, the page type has already
 * answered 404.
 *
 * ── ?variant= ─────────────────────────────────────────────────────────────
 *
 * Only a digit string without a leading zero naming a variant that is visible
 * on this page. Anything else is an address Consho never builds and answers
 * 404 (plan B-5 decision 3, answer 2): empty, 0, letters, an array, the main
 * product, another product's variant, an unpublished one, or one hidden in this
 * language because its own safety texts are not translated.
 *
 * Without the parameter nothing is preselected, unless exactly one variant is
 * visible — preselecting "the first" would put one variant's price and GTIN on
 * the canonical address of the whole product group.
 *
 * ── ⚠️ The type is declared, not derived ──────────────────────────────────
 *
 * Contao would derive "product_detail" from the class name and the element
 * would silently render nothing — see ProductListController, where that was
 * measured.
 */
#[AsContentElement(type: 'consho_product_detail', category: 'consho')]
class ProductDetailController extends AbstractContentElementController
{
    /**
     * A variant of the partial identifier, one or more path segments deep.
     * No dots, so no way out of the identifier's folder and no file extension.
     * Upper case is allowed: Contao's Finder takes the identifier from the file
     * name, and a partial it offers must also render.
     */
    private const PARTIAL = '%^'.DetailPartialOptionsListener::IDENTIFIER.'(?:/[a-zA-Z0-9_-]+)+\z%';

    public function __construct(
        private readonly ShopResolver $shops,
        private readonly ShopLanguages $languages,
        private readonly ProductDetailRepository $details,
        private readonly VariantRepository $variants,
        private readonly ArticlePriceResolver $prices,
        // Connect-1 (K2): the rules of the one-time offer, shared with the
        // catalogue reader so a feed cannot name a price this page does not.
        private readonly OneTimeOffer $oneTime,
        private readonly PriceAudienceResolver $audiences,
        private readonly MoneyFormatter $money,
        private readonly ProductSafetyResolver $safety,
        private readonly ProductUrlGenerator $urls,
        private readonly ProductHead $head,
        private readonly Studio $studio,
        private readonly ProductJsonLd $jsonLd,
        private readonly DbalCatalogSchedule $schedule,
        // Abo-2 (P10): the plans on offer and their conditions. Optional for
        // the tests that do not look at plans; the container wires both.
        private readonly SubscriptionOffers|null $offers = null,
        private readonly SubscriptionLineTerms|null $lineTerms = null,
        /*
         * The kind of the article (DL1; acceptance DL, find 2): "zzgl.
         * Versandkosten" only where something is shipped. Optional for the
         * tests built by hand — without it the sentence stays as it was.
         */
        private readonly ArticleShippingDataInterface|null $articles = null,
        // Block G (G7): what is left. Optional for the tests built by hand —
        // without it the page says nothing about stock, as before.
        private readonly Availability|null $availability = null,
        /*
         * Block G (G12, G15): "Benachrichtigen, wenn wieder da" and the
         * confirmation from its mail. Members only; a guest's page — the one
         * in the shared cache — shows the hint to sign in. Optional for the
         * tests built by hand.
         */
        private readonly Security|null $security = null,
        private readonly StockNotices|null $notices = null,
        private readonly GuestOrderConfirmation|null $confirmations = null,
        // Block Wertgutscheine (acceptance WG-7): no "inkl. USt." under a
        // price that is not taxable. Optional like the one above.
        private readonly NotTaxableArticles|null $notTaxable = null,
        // Block GARAN: the manufacturer's durability guarantee. Optional like
        // the ones above — without it the page shows no label.
        private readonly DurabilityLabelImages|null $durability = null,
        // Connect-1 (K3): the shipping costs in the JSON-LD. Optional like the
        // ones above — without it the markup states none.
        private readonly ArticleShippingRates|null $shippingRates = null,
        // Zusatzfeld-Werte (ZW-4, Z3): the type's custom fields under
        // "Angaben" and in its partial. Optional like the ones above —
        // without it the page shows none.
        private readonly CustomFieldDisplay|null $customFields = null,
    ) {
    }

    /**
     * The partial a product type brings, as a Twig path — or '' for none.
     *
     * Only a variant of DetailPartialOptionsListener::IDENTIFIER. The stored
     * value is included by name, so anything else — a value written past the
     * DCA, another element's template — must not reach the include.
     *
     * @internal public for the test only
     */
    public static function partial(string $stored): string
    {
        return 1 === preg_match(self::PARTIAL, $stored) ? '@Contao/'.$stored.'.html.twig' : '';
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $template->set('product', null);
        $template->set('customFields', []);

        $page = $request->attributes->get('pageModel');
        $productId = (int) $request->attributes->get(ProductPageController::PRODUCT_ATTRIBUTE, 0);

        if (!$page instanceof PageModel || $productId < 1) {
            return $template->getResponse();
        }

        $shop = $this->shops->resolveForPage($page);

        if (null === $shop) {
            return $template->getResponse();
        }

        $shopId = (int) $shop->id;
        // The currency of this start point (spec 4.0) — a shop may be reached
        // in more than one.
        $currency = $this->shops->currencyOfPage($page);

        // Gross in the home country until a delivery country is known (C25).
        // That one does stay with the shop: it is the seat of whoever writes
        // the invoice, and no currency moves it.
        $country = (string) $shop->country;

        // The start point's language after loadDetails() — see
        // ProductListController. A plain cast rather than `?? ''`, for the
        // reason given in ProductUrlGenerator::languageOf().
        $language = (string) $page->language;
        $fallback = $this->languages->fallbackOf($shopId);
        $rootPageId = (int) $page->rootId;
        $now = time();

        $main = $this->details->row($productId, $language, $fallback);

        // Resolved by the page type a moment ago; deleted in between.
        if (null === $main) {
            return $template->getResponse();
        }

        $matrix = $this->withRequestedVariant(
            $this->variants->matrixFor($productId, $shopId, $now, $language, $fallback),
            $request,
        );

        $selectedId = $matrix->selectedId();
        $variant = null === $selectedId ? null : $this->details->row($selectedId, $language, $fallback);

        $detail = ProductDetailAssembler::assemble($main, $variant, $this->details->properties($productId, $language, $fallback));

        $figures = $this->figures($detail, $model);

        $template->set('product', $detail);
        $template->set('figures', $figures);
        // For the article shown: a chosen variant's values over its main
        // product's (Z2), in the page's language (Z1).
        $customFields = $this->customFields?->forArticle($detail->articleId, $language, $fallback) ?? [];
        $template->set('customFields', $customFields);
        // The main product's, so it stands before a variant is chosen too (GA2).
        $template->set('garan', $this->durability?->of([$detail->articleId])[$detail->articleId] ?? null);
        /*
         * Block G (G7): the state of every article on this page — the chosen
         * one and each variant an option leads to. Only kept stock speaks; an
         * unlimited article says nothing, so a shop without stock looks as
         * before. Sold out: the page, its address and the sitemap entry stay
         * (spec A 3.7), only the buy button goes.
         */
        $targets = [];

        foreach ($matrix->axes() as $axis) {
            foreach ($axis->options as $option) {
                if (null !== $option->targetId) {
                    $targets[] = $option->targetId;
                }
            }
        }

        $stock = $this->availability?->of(array_values(array_unique([$detail->articleId, ...$targets]))) ?? [];
        $articleStock = $matrix->needsChoice() ? null : ($stock[$detail->articleId] ?? null);

        $template->set('axes', $this->linkedAxes($matrix, $productId, $rootPageId, $stock));
        $template->set('stock', null === $articleStock?->available ? null : [
            'state' => $articleStock->state->value,
            'availableFrom' => null !== $articleStock->availableFrom && !\in_array($articleStock->state, [StockState::Available, StockState::Few], true)
                ? (string) (new \IntlDateFormatter(str_replace('-', '_', $language), \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($articleStock->availableFrom)
                : null,
        ]);

        // The chosen article's texts, or the product's without a choice
        // (plan B-5, handover from task 8). Never falls back to another language.
        $safety = $this->safety->forProduct($detail->articleId, $language, $fallback);
        $template->set('safety', $safety);

        // Asked once and shared by the price area and the JSON-LD, so the
        // markup can never name a price the page does not show.
        /*
         * Who this page prices for (C-4). Asked once, before any amount, so
         * the price, the scale table and the JSON-LD cannot end up disagreeing
         * about who is looking.
         */
        $audience = $this->audiences->forShop($shopId, $currency, $now);

        /*
         * The one-time offer (Connect-1, K2): null for an article not sold
         * once — a one-time price row left over from before is no offer, not
         * up top, not in the markup (Fable review Abo-2, L4a). OneTimeOffer
         * holds that rule and the struck price; the catalogue reader asks it
         * too. Home country twice: market and tax country are one here (C25).
         *
         * The offers are asked once and handed over: the plans below need
         * them as well (review Connect-1, H5).
         */
        $offered = $matrix->needsChoice() || null === $this->offers
            ? null
            : $this->offers->offered($detail->articleId, $audience->groups);

        $oneTime = $matrix->needsChoice() ? null : $this->oneTime->of($detail->articleId, $productId, $currency, $country, $country, $now, self::units($detail), $audience->groups, $offered);
        $articlePrice = $oneTime?->price;
        $variantPrices = $matrix->needsChoice() ? $this->variantPrices($main, $matrix, $currency, $country, $language, $fallback, $now, $audience) : [];

        $plans = null === $offered ? [] : $this->plans($offered, $detail, $productId, $articlePrice, $currency, $country, $now, $audience, $language);

        /*
         * A product not sold once shows its FIRST plan up top (P10) — the
         * operator's order says which one leads. Its one-time rows, should any
         * be left over, are not an offer and do not reach the page or the
         * markup: a monthly amount is no schema.org price either.
         */
        $headline = $articlePrice;
        $headlineTerms = null;

        if ([] !== $plans && 0 !== $plans[0]['rule']) {
            $headline = $plans[0]['articlePrice'];
            $headlineTerms = $plans[0]['terms'];
            $articlePrice = null;
        }

        if ($matrix->needsChoice()) {
            [$priceView, $headlineTerms] = $this->variantFromPrice($variantPrices, $productId, $currency, $country, $now, $audience, $language);
        } else {
            $priceView = $this->priceView($headline, false, $currency, $language, $audience->showsNet);

            /*
             * Block H (H-2): one article's one-time price — the headline is
             * that only while no plan took its place. A "from" over variants
             * names no single article, so it gets none, as on the tile.
             */
            $compare = null === $oneTime || null === $articlePrice ? null : $oneTime->compare($audience->showsNet);

            if (null !== $priceView && null !== $compare) {
                $priceView['compare'] = $this->money->format($compare, $currency, $language);
            }
        }

        if (null !== $priceView) {
            $priceView['terms'] = $headlineTerms;
        }

        $template->set('price', $priceView);
        $template->set('shipped', null === $this->articles || ProductKind::stored($this->articles->of([$detail->articleId])[$detail->articleId]['kind'] ?? null)->isPhysical());
        $template->set('untaxed', $this->notTaxable?->all([$detail->articleId]) ?? false);

        // Only where a subscription is among them: a product sold once and
        // nothing else renders exactly as before Abo-2.
        $template->set('plans', 1 < \count($plans) || ([] !== $plans && 0 !== $plans[0]['rule'])
            ? array_map(static fn (array $plan): array => array_diff_key($plan, ['articlePrice' => true]), $plans)
            : []);

        // The purchase area is prepared, not built (decision 11): the article
        // that would be bought, null while a variant still has to be chosen.
        // pageId travels with the form — the route reads the shop and the way
        // back off it, and a page id is the one thing in this form that
        // cannot be turned into somebody else's basket. $page is already this
        // request's PageModel (checked above); AbstractFragmentController's
        // own getPageModel() would only fetch the very same instance through
        // the page_finder service, for no benefit.
        $template->set('purchase', [
            // Sold out: no buy button (G7); the refusal of the add route is the
            // belt to this.
            'articleId' => $matrix->needsChoice() || false === $articleStock?->state->purchasable() ? null : $detail->articleId,
            'minPurchase' => $detail->minPurchase,
            'purchaseSteps' => $detail->purchaseSteps,
            'pageId' => (int) $page->id,
        ]);

        /*
         * Why a refused add says so here (C-3c): the add route sends the
         * visitor back with ?consho_notice=<case>, and this is the page they
         * land on. Looked up as an enumeration, never echoed — refusalFrom()
         * answers null for anything that is not one of our own REFUSAL cases,
         * and the template renders nothing for null. The visitor cannot put
         * text on this page through the query string, and cannot fake a
         * success on it either.
         *
         * query->all() rather than getString(): the latter throws a 400 for
         * ?consho_notice[]=1, and a product page that answers a crawler's
         * stray bracket with an error is noise in the log. Same reading as
         * the variant parameter in AddToCartController.
         *
         * NOT a fragment, and that is a decision: the value comes from the
         * address alone, never from the visitor's cookie or session, so the
         * page is the same for everybody who opens that address. It may stay
         * in the shared cache — at the price of one extra entry for the call
         * AFTER a failed click, which is not a path anybody walks twice.
         */
        $template->set('notice', CartNotice::refusalFrom($request->query->all()[CartNotice::PARAM] ?? null));

        /*
         * Block G (G12, G15). The wish is offered where the buy button went:
         * a sold-out article (not one sold on order). The member is read from
         * the session — a logged-in member's page is private, so nothing of
         * this reaches the shared cache; a guest's page says "sign in".
         */
        $user = $this->security?->getUser();
        $member = $user instanceof FrontendUser ? $user : null;
        $template->set('stockNoticeResult', StockNoticeResult::fromQuery($request->query->all()[StockNoticeResult::PARAM] ?? null));
        $template->set('stockNotice', null === $this->notices || null === $articleStock || $articleStock->state->purchasable() ? null : [
            'articleId' => $detail->articleId,
            'main' => $productId,
            'pageId' => (int) $page->id,
            'state' => null === $member ? 'login' : ($this->notices->isSubscribed($detail->articleId, (int) $member->id) ? 'subscribed' : 'subscribe'),
        ]);

        // The link of "wieder lieferbar" (G15): its token offers to confirm
        // the address — by button, signed in as the account it was sent to.
        $token = $request->query->all()[EmailConfirmation::PARAM] ?? null;
        $template->set('emailConfirm', null === $this->confirmations || !\is_string($token) || '' === $token ? null : [
            'token' => $token,
            'articleId' => $detail->articleId,
            'main' => $productId,
            'pageId' => (int) $page->id,
            // Not `$member->id ?? 0`: `??` asks __isset first. Contao's User
            // answers it, a test double with only __get does not — and then
            // the member reads as nobody. Explicit, so the code does not
            // depend on which of the two it meets.
            'state' => $this->confirmations->state($token, null === $member ? 0 : (int) $member->id, null === $member ? '' : (string) $member->email, ConfirmationPurpose::StockNotice),
        ]);

        $template->set('partial', self::partial($detail->detailPartial));

        // The PRODUCT's id, title, meta description and teaser, never the
        // variant's: a variant address is canonical to its product (ProductHead).
        $this->head->apply($productId, $rootPageId, $main->title, $main->metaDescription, $main->teaser);

        $jsonLd = $this->jsonLd->build(
            $main->title,
            $main->teaser,
            $main->description,
            $detail->sku,
            $detail->mpn,
            // A GTIN is never inherited (B-5), so none while the choice is open.
            $matrix->needsChoice() ? '' : $detail->gtin,
            $safety->manufacturerName,
            /*
             * Gross, whatever the visitor reads (C-4). schema.org `price` is
             * the amount payable including tax, so a net figure here would be
             * a wrong statement, not a different presentation of a right one.
             *
             * It stays the price OF THIS PAGE, group price included: a page
             * offering one visitor 8.00 net is offering 9.60, and that is what
             * its markup says. No crawler ever reads that version — Contao
             * makes a page private the moment a member is logged in.
             */
            $articlePrice?->gross,
            // One-time prices only: a monthly amount is no schema.org price
            // either. So with mixed variants the markup's lowPrice can lie
            // above the "from" the page shows, and offerCount counts only
            // the one-time ones (second Fable review Abo-2b, 4) — deliberate.
            array_values(array_map(static fn (array $offer): string => $offer['price']->gross, array_filter($variantPrices, static fn (array $offer): bool => 0 === $offer['rule']))),
            $currency,
            $this->offerUrl($request, $matrix, $productId, $rootPageId),
            $this->imageAddresses($figures, $request),
            // Block G (G7): what a crawler is told follows the stock. With
            // the choice open: in stock as long as one variant is.
            null !== $articleStock
                ? $articleStock->state
                : StockState::best(array_values(array_map(static fn ($availability): StockState => $availability->state, array_intersect_key($stock, array_flip($targets))))),
            null === $oneTime || null === $articlePrice ? null : $this->offerDetails($shop, $oneTime, $detail, $currency, $language, $fallback, $now, $audience->groups),
        );

        // No price, no markup (plan B-6, decision E3).
        if (null !== $jsonLd) {
            $this->jsonLd->apply($jsonLd, $productId);
        }

        $response = $template->getResponse();

        /*
         * The core's record tag of the MAIN product: Consho adds it for a
         * variant and for any price as well (CatalogCacheTagListener). Shared
         * records — manufacturer, type, options, shop — move CATALOG (B-7b).
         */
        $this->tagResponse([CatalogCacheTags::CATALOG, CatalogCacheTags::product($productId)]);

        // A variant or a price beginning or ending changes this page then (B-7b, E2).
        $nextChange = $this->schedule->nextDetailChange($productId, $now);

        if (null !== $nextChange) {
            $response->setSharedMaxAge(max(1, $nextChange - $now));
        }

        return $response;
    }

    /**
     * @throws PageNotFoundException for a parameter Consho never builds
     */
    private function withRequestedVariant(VariantMatrix $matrix, Request $request): VariantMatrix
    {
        // Through query->all() as with cq and page_c<ID>: InputBag::get()
        // answers ?variant[]=1 with a 400.
        $raw = $request->query->all()[ProductUrlGenerator::VARIANT_PARAMETER] ?? null;

        if (null === $raw) {
            return $matrix;
        }

        $chosen = \is_string($raw) && 1 === preg_match('/^[1-9][0-9]*\z/', $raw) ? $matrix->withSelection((int) $raw) : null;

        // Thrown from the element on purpose: it runs as a forward sub-request
        // with ignore_errors false, and Contao renders its 404 page from it.
        if (null === $chosen) {
            throw new PageNotFoundException(\sprintf('Page not found: %s', $request->getUri()));
        }

        return $chosen;
    }

    /**
     * Each option with the address of the variant it leads to.
     *
     * @return list<VariantAxisView>
     */
    /**
     * @param array<int, ArticleAvailability> $stock by article id (Block G)
     */
    private function linkedAxes(VariantMatrix $matrix, int $productId, int $rootPageId, array $stock = []): array
    {
        $hasProductPage = true;
        $axes = [];

        foreach ($matrix->axes() as $axis) {
            $options = [];

            foreach ($axis->options as $option) {
                $url = null;

                if (null !== $option->targetId && $hasProductPage) {
                    try {
                        $url = $this->urls->generate($productId, $rootPageId, UrlGeneratorInterface::ABSOLUTE_PATH, $option->targetId);
                    } catch (ProductPageMissing) {
                        // Hardly possible while standing on the product page.
                        // Without an address the options stay, unlinked — and
                        // every option shares the root page, so ask no further.
                        $hasProductPage = false;
                    }
                }

                $options[] = $option->withUrl($url)->withUnavailable(
                    null !== $option->targetId && false === ($stock[$option->targetId] ?? null)?->state->purchasable(),
                );
            }

            $axes[] = $axis->withOptions($options);
        }

        return $axes;
    }

    /**
     * The price of every visible variant that has one, in display order —
     * each variant's FIRST offer with a price, as up top for a single article
     * (Abo-2b, Fable review S1): the one-time sale, or for a product not sold
     * once its first plan. The tile takes the same figure (P88); without this
     * a variant product sold only as a plan had a "from" price on the tile
     * and none on its page.
     *
     * @return list<array{price: ArticlePrice, rule: int, variant: int}>
     */
    private function variantPrices(ProductDetailRow $main, VariantMatrix $matrix, string $currency, string $country, string $language, string $fallback, int $now, PriceAudience $audience): array
    {
        $prices = [];

        foreach ($matrix->variantIds() as $variantId) {
            $row = $this->details->row($variantId, $language, $fallback);

            if (null === $row) {
                continue;
            }

            // The assembler decides whose unit set applies, as for a chosen variant.
            $units = self::units(ProductDetailAssembler::assemble($main, $row, []));
            $offered = null === $this->offers ? [0 => null] : $this->offers->offered($variantId, $audience->groups);

            foreach (array_keys($offered) as $rule) {
                $price = $this->prices->forArticle($variantId, $main->id, $currency, $country, $now, $units, $audience->groups, $rule);

                // A variant without a price in this currency is no cheaper than any other.
                if (null !== $price) {
                    $prices[] = ['price' => $price, 'rule' => $rule, 'variant' => $variantId];

                    break;
                }
            }
        }

        return $prices;
    }

    /**
     * The "from" price of the variants, with the terms of its plan when the
     * cheapest variant's figure is a plan's (Abo-2b, S1).
     *
     * @param list<array{price: ArticlePrice, rule: int, variant: int}> $offers
     *
     * @return array{array<string, mixed>|null, string|null}
     */
    private function variantFromPrice(array $offers, int $productId, string $currency, string $country, int $now, PriceAudience $audience, string $language): array
    {
        $view = $this->fromPrice(array_column($offers, 'price'), $currency, $language, $audience->showsNet);
        [$cheapest] = ArticlePriceResolver::fromPrice(array_column($offers, 'price'), $audience->showsNet);

        if (null === $cheapest) {
            return [$view, null];
        }

        /*
         * Which offer the figure is: among those at the cheapest amount, the
         * one-time sale first, then the lowest rule id — the tie rule of the
         * tile (DbalProductTileRepository, ORDER BY amount, subscriptionRule;
         * second Fable review Abo-2b, 1). fromPrice() alone would take the
         * first in display order, and a tile reading "5,00 €" would face a
         * page reading "5,00 €" with the terms of a plan.
         */
        [$amount] = $cheapest->display($audience->showsNet);
        $chosen = null;

        foreach ($offers as $offer) {
            if (!BigDecimal::of($offer['price']->display($audience->showsNet)[0])->isEqualTo($amount)) {
                continue;
            }

            if (null === $chosen || ($chosen['rule'] > 0 && (0 === $offer['rule'] || $offer['rule'] < $chosen['rule']))) {
                $chosen = $offer;
            }
        }

        if (null === $chosen || 0 === $chosen['rule']) {
            return [$view, null];
        }

        return [$view, $this->lineTerms?->of($chosen['rule'], $amount, $currency, $language)];
    }

    /**
     * The price while no article is determined: the cheapest visible variant,
     * "from" only when the variants really differ — the same rule as the tile.
     *
     * No scale table: the steps belong to one article, and which one is not
     * decided yet. The base price is the cheapest variant's own, next to its
     * price, as the price indication rules want it.
     *
     * @param list<ArticlePrice> $prices
     *
     * @return array<string, mixed>|null
     */
    private function fromPrice(array $prices, string $currency, string $language, bool $showsNet): ?array
    {
        [$cheapest, $differs] = ArticlePriceResolver::fromPrice($prices, $showsNet);

        if (null === $cheapest) {
            return null;
        }

        $view = $this->priceView($cheapest, $differs, $currency, $language, $showsNet);

        if (null !== $view) {
            $view['scale'] = [];
        }

        return $view;
    }

    /**
     * Formatted for the page's language. Gross in the shop's home country, for
     * the anonymous visitor — the same question the listing asks until block C
     * knows who is logged in.
     *
     * @return array<string, mixed>|null
     */
    private function priceView(ArticlePrice|null $price, bool $isFrom, string $currency, string $language, bool $showsNet): ?array
    {
        // Visible without a price: the page stays, without a price area
        // (decision 10).
        if (null === $price) {
            return null;
        }

        [$amount, $basePrice] = $price->display($showsNet);

        $scale = [];

        foreach ($price->scale as $step) {
            // Each step reads the way the price above it does. A scale in
            // gross under a net price would be a second, contradicting offer.
            $stepAmount = $showsNet ? $step->price->net : $step->gross;

            $scale[] = [
                'quantity' => $this->money->quantity($step->minQuantity, $language),
                'amount' => $stepAmount,
                'formatted' => $this->money->format($stepAmount, $currency, $language),
            ];
        }

        return [
            'amount' => $amount,
            'currency' => $currency,
            'formatted' => $this->money->format($amount, $currency, $language),
            'isFrom' => $isFrom,
            // What the template prints "zzgl. USt." off, and the only thing
            // that decides it: one flag, one place.
            'showsNet' => $showsNet,
            'basePrice' => '' === $basePrice ? null : [
                'formatted' => $this->money->format($basePrice, $currency, $language),
                'quantity' => $this->money->quantity($price->referenceUnit, $language),
                'unit' => $price->unit,
            ],
            'scale' => $scale,
        ];
    }

    /**
     * The plans on offer for the chosen article (Abo-2, P10/P11): one-time
     * sale first when offered, then the rules in the operator's order. A plan
     * without a price in this currency is not offered — a radio button
     * without an amount would be an offer nobody made. The first one is
     * checked.
     *
     * "Business" here is the net price audience: a verified VAT ID only exists
     * in the checkout, which asks again (CheckoutCalculator).
     *
     * @param array<int, SubscriptionRule|null> $offered what SubscriptionOffers::offered() answered for this article and visitor
     *
     * @return list<array{rule: int, label: string|null, price: string, terms: string|null, checked: bool, articlePrice: ArticlePrice}>
     */
    private function plans(array $offered, ProductDetail $detail, int $productId, ArticlePrice|null $oncePrice, string $currency, string $country, int $now, PriceAudience $audience, string $language): array
    {
        $plans = [];

        foreach ($offered as $ruleId => $rule) {
            $price = null === $rule
                ? $oncePrice
                : $this->prices->forArticle($detail->articleId, $productId, $currency, $country, $now, self::units($detail), $audience->groups, $ruleId);

            if (null === $price) {
                continue;
            }

            [$amount] = $price->display($audience->showsNet);

            $plans[] = [
                'rule' => $ruleId,
                // null = the one-time sale; the template names it in its language.
                'label' => $rule?->title,
                'price' => $this->money->format($amount, $currency, $language),
                'terms' => null === $rule ? null : $this->lineTerms?->of($ruleId, $amount, $currency, $language),
                'checked' => [] === $plans,
                'articlePrice' => $price,
            ];
        }

        return $plans;
    }

    /**
     * Built as Contao's own image element builds them; a deleted file drops
     * out without a trace (ImagesController).
     *
     * @return list<Figure>
     */
    private function figures(ProductDetail $detail, ContentModel $model): array
    {
        if ([] === $detail->images) {
            return [];
        }

        // Read through row(): conshoGallerySize is Consho's own column. fullsize
        // is the core's, read the same way so one source serves both.
        $row = $model->row();

        $builder = $this->studio
            ->createFigureBuilder()
            ->setSize(StringUtil::deserialize($row['conshoGallerySize'] ?? null, true))
            ->setLightboxGroupIdentifier('lb'.(int) $model->id)
            ->enableLightbox((bool) ($row['fullsize'] ?? false))
        ;

        $figures = [];

        foreach ($detail->images as $uuid) {
            $figure = $builder->fromUuid($uuid)->buildIfResourceExists();

            if (null !== $figure) {
                $figures[] = $figure;
            }
        }

        return $figures;
    }

    /**
     * The address of what the offer offers: with ?variant= when the visitor
     * chose the variant through the address, otherwise the canonical address.
     */
    private function offerUrl(Request $request, VariantMatrix $matrix, int $productId, int $rootPageId): string
    {
        // withRequestedVariant() has already refused an invalid parameter, so a
        // present one names the selected variant.
        $chosen = \array_key_exists(ProductUrlGenerator::VARIANT_PARAMETER, $request->query->all()) ? (int) $matrix->selectedId() : 0;

        try {
            return $this->urls->generate($productId, $rootPageId, UrlGeneratorInterface::ABSOLUTE_URL, $chosen);
        } catch (ProductPageMissing) {
            return '';
        }
    }

    /**
     * Structured data needs absolute image addresses. Contao's src is relative
     * unless a static URL is configured (Figure::getSchemaOrgData() passes it
     * on as it is).
     *
     * @param list<Figure> $figures
     *
     * @return list<string>
     */
    private function imageAddresses(array $figures, Request $request): array
    {
        $addresses = [];

        foreach ($figures as $figure) {
            $src = $figure->getImage()->getImageSrc();

            if ('' === $src) {
                continue;
            }

            $addresses[] = match (true) {
                1 === preg_match('~^https?://~i', $src) => $src,
                str_starts_with($src, '//') => $request->getScheme().':'.$src,
                default => $request->getSchemeAndHttpHost().$request->getBasePath().'/'.ltrim($src, '/'),
            };
        }

        return $addresses;
    }

    /**
     * What the Offer of the determined article says beyond its price
     * (Connect-1, S6): the base price where there is one, the struck price
     * the page shows, and for goods that are shipped the cheapest shipping
     * per delivery country (K3) and the return window (K4).
     *
     * Gross throughout, as the markup's price (C-4).
     *
     * @param list<int> $groups
     */
    private function offerDetails(ShopModel $shop, OneTimePrice $oneTime, ProductDetail $detail, string $currency, string $language, string $fallback, int $now, array $groups): OfferDetails
    {
        $row = $shop->row();
        $units = '' === $oneTime->price->basePrice ? null : self::units($detail);
        $physical = null === $this->articles || ProductKind::stored($this->articles->of([$detail->articleId])[$detail->articleId]['kind'] ?? null)->isPhysical();

        if (!$physical) {
            return new OfferDetails($oneTime->compareGross, $units);
        }

        $context = new CatalogContext(
            shopId: (int) $shop->id,
            rootPageId: 0,
            language: $language,
            fallback: $fallback,
            currency: $currency,
            country: (string) $shop->country,
            shopCountry: strtoupper(trim((string) $shop->country)),
            taxAtDestination: (bool) ($row['taxAtDestination'] ?? false),
            deliveryCountries: array_values(array_filter(array_map(
                static fn (mixed $code): string => strtoupper(trim((string) $code)),
                StringUtil::deserialize($row['deliveryCountries'] ?? null, true),
            ))),
            groups: $groups,
            now: $now,
        );

        $shipping = [];

        foreach (null === $this->shippingRates ? [] : $context->servedCountries() as $country) {
            $rate = $this->shippingRates->cheapest($context, $country, $detail->articleId, $oneTime->price->price->net, $detail->minimumAge);

            if (null !== $rate) {
                $shipping[] = $rate;
            }
        }

        // The shop's delivery time; 0 as the maximum means "not stated".
        $maxDays = (int) ($row['deliveryDaysMax'] ?? 0);

        return new OfferDetails(
            compareGross: $oneTime->compareGross,
            units: $units,
            shipping: $shipping,
            deliveryDays: $maxDays > 0 ? [min((int) ($row['deliveryDaysMin'] ?? 0), $maxDays), $maxDays] : null,
            returnDays: (int) ($row['withdrawalDays'] ?? 0),
            returnCountries: $context->servedCountries(),
        );
    }

    /**
     * @return array{unit: string, purchaseUnit: string, referenceUnit: string}
     */
    private static function units(ProductDetail $detail): array
    {
        return ['unit' => $detail->unit, 'purchaseUnit' => $detail->purchaseUnit, 'referenceUnit' => $detail->referenceUnit];
    }
}
