<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Catalog\VisibilityCriteria;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;

/**
 * Whether an article may go into a basket (7.1, step 3).
 *
 * The visibility rules are NOT restated here: VisibilityCriteria is the one
 * place they live (listing, product page, sitemap, JSON-LD — and now the
 * basket). A rule copied into a fifth place is the rule that gets forgotten
 * when it changes. It hands out a SqlCondition — a WHERE fragment plus its
 * parameters — which goes into the query below unchanged.
 *
 * Minimum quantity and step come from the MAIN product: a variant inherits
 * them, the same way it inherits prices and safety texts.
 *
 * Deliberately not checked here: stock. "Sold out" is not a visibility case
 * (VisibilityCriteria says so at length), and there is no stock in Consho yet.
 */
final class PurchaseCheck
{
    /** The named parameter for the article, chosen not to clash with the criteria's own. */
    private const ARTICLE = 'consho_article';

    public function __construct(
        private readonly Connection $connection,
        private readonly VisibilityCriteria $criteria,
        // Abo-2: whether the chosen plan is offered. Optional for the tests
        // that check visibility and quantity only; the container wires it.
        private readonly SubscriptionOffers|null $offers = null,
    ) {
    }

    /**
     * Both language parameters are mandatory — no default. A caller that
     * omits one previously did so silently: leaving out $language turned off
     * the fifth visibility rule outright, and passing $language without
     * $fallback made VisibilityCriteria treat the site's own main language
     * as a foreign one (see the class docblock's rule 4/5 and b133108, which
     * fixed the first of those two ways to get this wrong).
     *
     * @param string $language the page's language, so that the fifth visibility
     *                         rule applies too: an article whose own texts are
     *                         untranslated is invisible on that page, and what a
     *                         page does not show must not go into a basket from
     *                         it. Every caller that has a page in play MUST pass
     *                         both $language and $fallback; the main/default
     *                         language is passed as $language === $fallback
     *                         (e.g. 'de', 'de'), never as two empty strings —
     *                         VisibilityCriteria treats an empty $language
     *                         together with a non-empty $fallback (or the other
     *                         way round) as "needs translation", which rejects a
     *                         perfectly normal product in the site's own
     *                         language. Pass '', '' only where no page is
     *                         involved at all.
     * @param string    $fallback         the root page's fallback language
     * @param int|null  $subscriptionRule the chosen plan (Abo-2, P10): 0 = one-time sale.
     *                                    NULL when the plan is not in question — a
     *                                    quantity change of a line that already has
     *                                    one. Defaulting to 0 there would refuse every
     *                                    subscription-only product; whether the line's
     *                                    plan still applies is the pricer's answer.
     * @param list<int> $groups           the visitor's member groups — they alone decide which plans are offered (variant A)
     */
    public function check(int $articleId, int $shopId, string $quantity, int $now, string $language, string $fallback, int|null $subscriptionRule = null, array $groups = []): PurchaseVerdict
    {
        // Fetches minPurchase/purchaseSteps for the article's OWN row already:
        // for a main product without variants (the common case) that row IS
        // the limits row, so the second query below is skipped entirely — it
        // only remains necessary for a variant, whose limits live on its main
        // product instead.
        $row = $this->connection->fetchAssociative(
            'SELECT id, pid, minPurchase, purchaseSteps FROM tl_consho_product WHERE id = ?',
            [$articleId],
        );

        if (false === $row) {
            return PurchaseVerdict::refuse(PurchaseVerdict::UNAVAILABLE);
        }

        $mainProductId = (int) $row['pid'] ?: $articleId;

        if (!$this->isVisible($articleId, $mainProductId, $shopId, $now, $language, $fallback)) {
            return PurchaseVerdict::refuse(PurchaseVerdict::UNAVAILABLE);
        }

        // A main product that HAS variants is not merchandise, it is the
        // choice in front of it. VisibilityCriteria shows it in the listing —
        // buying it is a different question.
        if ($articleId === $mainProductId && $this->hasVariants($articleId)) {
            return PurchaseVerdict::refuse(PurchaseVerdict::NEEDS_CHOICE);
        }

        if ($articleId === $mainProductId) {
            $limits = $row;
        } else {
            $limits = $this->connection->fetchAssociative(
                'SELECT minPurchase, purchaseSteps FROM tl_consho_product WHERE id = ?',
                [$mainProductId],
            );

            if (false === $limits) {
                // A variant whose main product is gone. isVisible() already
                // rules it out; this keeps the reading of the row honest
                // either way.
                return PurchaseVerdict::refuse(PurchaseVerdict::UNAVAILABLE);
            }
        }

        $normalised = Quantity::normalise(
            $quantity,
            (string) $limits['minPurchase'],
            (string) $limits['purchaseSteps'],
        );

        if (null === $normalised) {
            return PurchaseVerdict::refuse(PurchaseVerdict::INVALID_QUANTITY);
        }

        // Spec F, open points (K6): a code or a voucher comes whole — 1.5 keys
        // were released as one. A service may be 1.5 hours, goods 1.5 kg. The
        // kind sits at the main product's type, as everywhere.
        if (!BigDecimal::of($normalised)->getFractionalPart()->isZero() && $this->wholeOnly($mainProductId)) {
            return PurchaseVerdict::refuse(PurchaseVerdict::INVALID_QUANTITY);
        }

        /*
         * Asked of the ARTICLE, not the main product: SubscriptionOffers reads
         * a variant's plans off its main product itself (SaleModes). Without
         * the offers service only the one-time sale can be answered safely.
         */
        if (null !== $subscriptionRule) {
            $offered = null === $this->offers
                ? 0 === $subscriptionRule
                : $this->offers->allows($articleId, $subscriptionRule, $groups);

            if (!$offered) {
                return PurchaseVerdict::refuse(PurchaseVerdict::NOT_OFFERED);
            }
        }

        return PurchaseVerdict::allow($normalised, $mainProductId);
    }

    private function isVisible(int $articleId, int $mainProductId, int $shopId, int $now, string $language, string $fallback): bool
    {
        // The alias has to be passed explicitly: the criteria default to `p`
        // for a main product and `v` for a variant, and the query below uses
        // one table alias for both.
        $condition = $articleId === $mainProductId
            ? $this->criteria->mainProduct($shopId, $now, 'p', $language, $fallback)
            : $this->criteria->variant($shopId, $now, 'p', $language, $fallback);

        return 0 < (int) $this->connection->fetchOne(
            \sprintf('SELECT COUNT(*) FROM tl_consho_product p WHERE p.id = :%s AND %s', self::ARTICLE, $condition->sql),
            [self::ARTICLE => $articleId, ...$condition->params],
        );
    }

    private function hasVariants(int $productId): bool
    {
        return 0 < (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_product WHERE pid = ?', [$productId]);
    }

    private function wholeOnly(int $mainProductId): bool
    {
        $kind = ProductKind::stored((string) $this->connection->fetchOne(
            'SELECT t.kind FROM tl_consho_product p JOIN tl_consho_product_type t ON t.id = p.productType WHERE p.id = ?',
            [$mainProductId],
        ));

        return ProductKind::Digital === $kind || $kind->isVoucher();
    }
}
