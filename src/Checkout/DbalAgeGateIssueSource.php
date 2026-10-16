<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Shipping\ShippingMethodTypes;

/**
 * Published articles from 16 or 18 that nobody can order (C19, 11.3).
 *
 * AgeGate needs one of two things: a customer group the operator marked as
 * age-verified, or a shipping method that checks at hand-over from that level
 * up. With NEITHER configured, such an article sits on the shelf and refuses
 * every order — correctly, and invisibly to whoever put it there.
 *
 * ── Why the shop, not the article, decides ────────────────────────────────
 *
 * Both mechanisms are shop configuration; the article only says from what age.
 * So the query asks per shop whether either exists at all, and then counts the
 * articles that would be stuck. A shop with either one configured produces no
 * finding, whatever the shipping method the visitor ends up choosing — whether
 * THIS order can go through is the checkout's question, and it answers it
 * there with AgeNotVerifiable.
 *
 * ── Counting only ─────────────────────────────────────────────────────────
 *
 * The sentence names a number and the shop, never the articles. It is asked on
 * every back end page (block B, section 10), and 16 is the lowest level that
 * blocks — 6, 12 and 14 are notices by definition and belong to no finding.
 */
final class DbalAgeGateIssueSource implements ConfigurationIssueSource
{
    /**
     * The lowest age AgeGate blocks on. Repeated from AgeGate::CHECKED_FROM
     * rather than shared, because this is a SQL literal and that one is a
     * guard clause — a test holds the two together.
     */
    public const CHECKED_FROM = 16;

    public function __construct(
        private readonly Connection $connection,
        private readonly ShippingMethodRepositoryInterface $shippingMethods,
        private readonly ShippingMethodTypes $shippingTypes,
    ) {
    }

    public function issues(): array
    {
        $issues = [];

        foreach ($this->connection->fetchAllAssociative('SELECT id, title, ageVerifiedGroups FROM tl_consho_shop ORDER BY id') as $shop) {
            if ([] !== MethodSettings::selectedIds($shop['ageVerifiedGroups'] ?? null)) {
                continue;
            }

            /*
             * ⚠️ Die STUFE zählt, nicht die bloße Existenz einer Prüfung. Eine
             * Versandart, die ab 16 prüft, trägt keine Lieferung ab 18 — der
             * Checkout weist sie mit AgeNotVerifiable ab, und genau diesen
             * stillen Zustand soll der Befund melden. Vor dem Review vom
             * 2026-09-22 genügte hier irgendein `ageCheck !== 0`, womit die
             * 16er-Art den Befund für 18er-Artikel abgeschaltet hätte.
             */
            $highestCheck = $this->highestShippingAgeCheck((int) $shop['id']);
            $threshold = max(self::CHECKED_FROM, $highestCheck + 1);

            $count = (int) $this->connection->fetchOne(
                'SELECT COUNT(*)
                 FROM tl_consho_product p
                 JOIN tl_consho_product_shop s ON s.pid = p.id
                 WHERE s.shop = ? AND p.published = 1 AND p.minimumAge >= ?',
                [(int) $shop['id'], $threshold],
            );

            if ($count > 0) {
                $issues[] = new ConfigurationIssue(
                    ConfigurationFinding::AgeGateMissing,
                    ['count' => (string) $count, 'shop' => (string) $shop['title']],
                    (int) $shop['id'],
                );
            }
        }

        return $issues;
    }

    /**
     * ⚠️ Reads the TYPE's capabilities, which live in CODE and not in a column,
     * so this cannot be a join — it asks the same registry the checkout asks.
     *
     * In C nothing answers true: the flat rate checks nothing, and it is the
     * only type shipped. Written out all the same, because block E's carriers
     * then switch this finding off by existing, with nothing here to remember
     * — and because a hard-coded false would be a second place where "C has no
     * age-checking carrier" is written down.
     *
     * The currency is irrelevant to the question and cannot be known per shop
     * here (it lives on the start point since C-1c). An empty one is passed
     * deliberately: it costs the methods their price rows, which capabilities()
     * does not look at.
     */
    private function highestShippingAgeCheck(int $shopId): int
    {
        $highest = 0;

        foreach ($this->shippingMethods->forShop($shopId, '') as $method) {
            if (!$this->shippingTypes->has($method->type)) {
                continue;
            }

            $highest = max($highest, $this->shippingTypes->get($method->type)->capabilities($method->config)->ageCheck);
        }

        return $highest;
    }
}
