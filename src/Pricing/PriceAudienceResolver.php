<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Contao\FrontendUser;
use Contao\StringUtil;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Turns "who is looking" into "how this shop prices for them" (C-4).
 *
 * The seam block B left open: every price call in the catalogue passes a
 * customer group, and until now every one of them passed nothing. See
 * ArticlePriceResolver, which has said so in a comment since B-5.
 *
 * The member's groups come from the token, the same way CartResolver reads the
 * member id. Which of them mean net is the shop's business, so the shop id has
 * to be named — the same member can be a trade customer of one shop and a
 * private buyer of another.
 *
 * ⚠️ A group selected in `netPriceGroups` that no longer exists is skipped
 * here without a word; DbalNetPriceGroupIssueSource is what says it out loud,
 * on the back end start page. Skipping and reporting are separate on purpose:
 * a front-end request must not depend on the operator having read a finding.
 */
final class PriceAudienceResolver
{
    public function __construct(
        private readonly TokenStorageInterface $tokens,
        private readonly Connection $connection,
    ) {
    }

    public function forShop(int $shopId, string $currency = '', int $now = 0): PriceAudience
    {
        $user = $this->tokens->getToken()?->getUser();

        if (!$user instanceof FrontendUser) {
            return PriceAudience::guest();
        }

        /*
         * In production `groups` is already an array by the time it gets here:
         * FrontendUser::setUserFromDb() deserialises it and intersects it with
         * the ACTIVE groups, so a disabled group never reaches us. In a test it
         * is usually the stored string. deserialize() takes either.
         */
        /** @var list<int> $groups */
        $groups = array_values(array_filter(
            array_map('intval', StringUtil::deserialize($user->groups, true)),
            static fn (int $id): bool => $id > 0,
        ));

        if ([] === $groups) {
            return PriceAudience::guest();
        }

        return new PriceAudience(
            $groups,
            $this->showsNet($groups, $shopId),
            $this->labelOf($groups, $shopId, $currency, $now),
        );
    }

    /**
     * @param list<int> $groups
     */
    private function showsNet(array $groups, int $shopId): bool
    {
        $selected = $this->connection->fetchOne(
            'SELECT netPriceGroups FROM tl_consho_shop WHERE id = :id',
            ['id' => $shopId],
        );

        if (!\is_string($selected) || '' === $selected) {
            return false;
        }

        $net = array_map('intval', StringUtil::deserialize($selected, true));

        return [] !== array_intersect($groups, $net);
    }

    /**
     * The names of those of the visitor's groups that carry a price THIS
     * visitor could actually meet.
     *
     * Restricted four times over, and every restriction is the same point: the
     * hint says "your own price is on the product page", so it must not appear
     * where there is none to find.
     *
     *   - a price row at all — a newsletter group has none;
     *   - on an article of THIS shop — a group price in the other shop of the
     *     installation is not this visitor's;
     *   - in THIS currency — the pound catalogue says nothing about euro rows;
     *   - valid NOW — an expired action is not an own price any more.
     *
     * Shop and currency arrive empty from callers that do not know them; the
     * condition is then dropped rather than matched against '', which would
     * silence the hint everywhere. Same for `$now` at 0.
     *
     * The join also answers the deleted-group case for free — a row in
     * tl_consho_price may name a group that is gone, and it contributes no
     * name because there is none.
     *
     * @param list<int> $groups
     */
    private function labelOf(array $groups, int $shopId, string $currency, int $now): string
    {
        $conditions = ['g.id IN (:groups)', 'p.tstamp > 0'];
        $parameters = ['groups' => $groups];
        $types = ['groups' => ArrayParameterType::INTEGER];

        if ($shopId > 0) {
            /*
             * The shop rule of A, 3.7, in both halves: a variant INHERITS the
             * shops of its main product, and where it has a selection of its
             * own that selection is INTERSECTED with the product's. The second
             * half was missing until the second C-4 review round — a variant
             * narrowed to shop 2 made the hint speak in shop 1, where the
             * variant does not appear at all, which is the very thing this
             * condition exists to prevent.
             *
             * The shape is VisibilityCriteria::variantReachesShop(): no own
             * rows means inherit, own rows must contain this shop.
             */
            $conditions[] = 'EXISTS (
                 SELECT 1 FROM tl_consho_product a
                 JOIN tl_consho_product_shop ps ON ps.pid = COALESCE(NULLIF(a.pid, 0), a.id)
                 WHERE a.id = p.pid AND ps.shop = :shop
                   AND (NOT EXISTS (SELECT 1 FROM tl_consho_product_shop vs WHERE vs.pid = a.id)
                        OR EXISTS (SELECT 1 FROM tl_consho_product_shop vs WHERE vs.pid = a.id AND vs.shop = :shop)))';
            $parameters['shop'] = $shopId;
        }

        if ('' !== $currency) {
            $conditions[] = 'p.currency = :currency';
            $parameters['currency'] = $currency;
        }

        if ($now > 0) {
            // From inclusive, to exclusive, NULL for open — the price
            // repository's own rule.
            $conditions[] = '(p.validFrom IS NULL OR p.validFrom <= :now)';
            $conditions[] = '(p.validTo IS NULL OR p.validTo > :now)';
            $parameters['now'] = $now;
        }

        /** @var list<string> $names */
        $names = $this->connection->fetchFirstColumn(
            \sprintf(
                'SELECT DISTINCT g.name
                 FROM tl_member_group g
                 JOIN tl_consho_price p ON p.customerGroup = g.id
                 WHERE %s
                 ORDER BY g.name',
                implode(' AND ', $conditions),
            ),
            $parameters,
            $types,
        );

        return implode(', ', array_map('strval', $names));
    }
}
