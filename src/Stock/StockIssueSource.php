<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;

/**
 * Stock that is low or gone (Block G, G11) — kept stock and key pools in one
 * finding, at one threshold (stockWarning of the main product). Replaces the
 * key pool's own finding of F-6.
 *
 * Every article by name with what is left: the operator has to know WHICH
 * goods to reorder or which codes to buy. Only published articles; a variant
 * goes by its own title, the label it has in the back end.
 *
 * Sold on order (backorder) is listed too: it sells, but on a promise.
 */
final class StockIssueSource implements ConfigurationIssueSource
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Availability $availability,
    ) {
    }

    public function issues(): array
    {
        $low = array_values($this->low());

        return [] === $low ? [] : [new ConfigurationIssue(ConfigurationFinding::StockLow, ['count' => (string) \count($low), 'products' => implode(', ', $low)])];
    }

    /**
     * The articles behind the finding, also counted on the dashboard (DB3)
     * and listed behind its link (DB4) — one list, not two.
     *
     * @return array<int, string> article id => "title (left)"
     */
    public function low(): array
    {
        $titles = $this->connection->fetchAllKeyValue(
            'SELECT a.id, a.title
               FROM tl_consho_stock s
               JOIN tl_consho_product a ON a.id = s.article
              WHERE s.active = 1 AND a.published = 1
              ORDER BY a.id',
        );

        if ([] === $titles) {
            return [];
        }

        $low = [];

        foreach ($this->availability->of(array_map(intval(...), array_keys($titles))) as $article => $state) {
            if (null !== $state->available && StockState::Available !== $state->state) {
                $low[(int) $article] = \sprintf('%s (%s)', (string) $titles[$article], (string) BigDecimal::of($state->available)->strippedOfTrailingZeros());
            }
        }

        return $low;
    }
}
