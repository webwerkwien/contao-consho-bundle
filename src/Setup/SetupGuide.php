<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Setup;

use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Checkout\CheckoutReadiness;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;

/**
 * What a shop still needs before it sells (block I).
 *
 * Reads the findings it is given — the caller already holds them, once per
 * request — and asks the database one thing only: whether a published start
 * point names the shop.
 *
 * ── Why that one question (I7) ────────────────────────────────────────────
 *
 * The page findings are read from the start points. A shop that no start
 * point names produces none of them, so CheckoutReadiness finds nothing that
 * blocks it — and a guide that trusted it alone would call a shop nobody can
 * reach "ready". Measured at the spec (2026-10-08), not assumed.
 */
final class SetupGuide
{
    /**
     * Warnings that are not in CheckoutReadiness::BLOCKING and still count
     * against "ready" (review I, S1).
     *
     * - CurrencyMissing, RootWithoutCurrency: measured — without a currency
     *   basket, mini basket and checkout answer empty (`'' === $currency` in
     *   CartController, MiniCartController, CheckoutController). The checkout
     *   needs no block for that, it has nothing to show; "ready" does, or it
     *   would vouch for an empty checkout.
     * - RootCurrencyNotSold: a decision, not a measurement (second review I,
     *   S2). The elements still render in that currency; but the shop no
     *   longer takes it, and shipping amounts in it do not exist. A shop
     *   selling in a currency it has given up is not "set up".
     *
     * Only for the shop the finding names — shop 0 does NOT mean "all" here
     * (second review I, B1: one shop's missing currency made every shop "not
     * ready"). The three sources carry the shop since then.
     *
     * Conservative with several start points of one shop: one without a
     * currency makes the shop "not ready" although another may sell — the
     * step says which.
     *
     * @var list<ConfigurationFinding>
     */
    public const UNSELLABLE = [
        ConfigurationFinding::CurrencyMissing,
        ConfigurationFinding::RootWithoutCurrency,
        ConfigurationFinding::RootCurrencyNotSold,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, string> id => title, in id order
     */
    public function shops(): array
    {
        return array_map(
            'strval',
            $this->connection->fetchAllKeyValue('SELECT id, title FROM tl_consho_shop ORDER BY id'),
        );
    }

    /**
     * @param list<ConfigurationIssue> $issues every finding, of every shop
     */
    public function forShop(int $shopId, string $title, array $issues, int $now): ShopSetup
    {
        $roots = $this->liveRoots($shopId, $now);

        $mine = array_values(array_filter(
            $issues,
            static fn (ConfigurationIssue $issue): bool => $issue->finding->isWarning() && (0 === $issue->shopId || $issue->shopId === $shopId),
        ));

        $stops = self::stops($shopId);

        $steps = [];

        foreach (SetupStep::cases() as $step) {
            $found = array_values(array_filter($mine, static fn (ConfigurationIssue $issue): bool => SetupStep::of($issue->finding) === $step));

            $status = match (true) {
                [] !== array_filter($found, $stops), SetupStep::Shop === $step && [] === $roots => StepState::OPEN,
                [] !== $found => StepState::ATTENTION,
                default => StepState::DONE,
            };

            $steps[] = new StepState($step, $status, $found);
        }

        $ready = [] !== $roots && [] === array_filter($mine, $stops);

        return new ShopSetup(
            $shopId,
            $title,
            $ready,
            $roots,
            $steps,
            array_values(array_filter($mine, static fn (ConfigurationIssue $issue): bool => null === SetupStep::of($issue->finding))),
        );
    }

    /**
     * The findings that keep this shop from selling — the reasons behind
     * "not ready", apart from a missing start point, which no finding names.
     * The dashboard asks this for its "who sees the reasons" line (H2), so
     * that line and "ready" share one rule.
     *
     * @param list<ConfigurationIssue> $issues
     *
     * @return list<ConfigurationIssue>
     */
    public static function stopping(int $shopId, array $issues): array
    {
        return array_values(array_filter($issues, self::stops($shopId)));
    }

    /**
     * @return \Closure(ConfigurationIssue): bool
     */
    private static function stops(int $shopId): \Closure
    {
        return static fn (ConfigurationIssue $issue): bool => CheckoutReadiness::blocks($issue, $shopId)
            || ($issue->shopId === $shopId && \in_array($issue->finding, self::UNSELLABLE, true));
    }

    /**
     * A start point a button may act on: one of the live start points of the
     * shop it names — the same set the page offers buttons for, so a forged
     * id gets nothing the page would not have offered.
     *
     * @return array{id: int, title: string, language: string, shop: int}|null
     */
    public function root(int $rootId, int $now): array|null
    {
        $shopId = (int) $this->connection->fetchOne("SELECT consho_shop FROM tl_page WHERE id = ? AND type IN ('root', 'rootfallback')", [$rootId]);

        foreach ($shopId > 0 ? $this->liveRoots($shopId, $now) : [] as $root) {
            if ($root['id'] === $rootId) {
                return [...$root, 'shop' => $shopId];
            }
        }

        return null;
    }

    /**
     * Published and inside start/stop, as the page findings read a start
     * point (DbalCheckoutReadinessIssueSource::liveRoot()).
     *
     * @return list<array{id: int, title: string, language: string}>
     */
    public function liveRoots(int $shopId, int $now): array
    {
        $minute = (string) ($now - $now % 60);

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, title, language FROM tl_page
              WHERE type IN ('root', 'rootfallback')
                AND consho_shop = :shop
                AND published = 1
                AND (start = '' OR start <= :now)
                AND (stop = '' OR stop > :now)
              ORDER BY sorting, id",
            ['shop' => $shopId, 'now' => $minute],
        );

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'language' => (string) $row['language']],
            $rows,
        );
    }
}
