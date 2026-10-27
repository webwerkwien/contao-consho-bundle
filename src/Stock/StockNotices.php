<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Stock;

use Doctrine\DBAL\Connection;

/**
 * "Benachrichtigen, wenn wieder da" (Block G, G12, G15): a member's wish, one
 * per article.
 *
 * A wish is taken only while the article cannot be bought — a wish for
 * something in stock would be answered by the next cron at once, with a mail
 * nobody needs. Members only (G15); the caller makes sure there is one.
 */
class StockNotices
{
    public function __construct(
        private readonly Connection $connection,
        private readonly Availability $availability,
    ) {
    }

    /**
     * @return bool whether the wish is (now) recorded
     */
    public function subscribe(int $article, int $memberId, int $shopId, int $rootId, string $language, int $now): bool
    {
        if ($memberId < 1 || $this->availability->for($article)->state->purchasable()) {
            return false;
        }

        if ($this->isSubscribed($article, $memberId)) {
            return true;
        }

        if (!$this->visibleIn($article, $shopId, $language)) {
            return false;
        }

        $main = $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$article]);

        if (false === $main) {
            return false;
        }

        $this->connection->insert('tl_consho_stock_notice', [
            'article' => $article,
            'main' => (int) $main > 0 ? (int) $main : $article,
            'member' => $memberId,
            'shop' => $shopId,
            'root' => $rootId,
            'language' => $language,
            'createdAt' => $now,
            'tstamp' => $now,
        ]);

        return true;
    }

    /**
     * Only for an article the start point shows (Nachlese K5, G-7):
     * the route took any article id, so a member could wish for one of
     * another shop or language and get a mail with a link that leads nowhere.
     * Read from the visibility projection, where the listing and the product
     * page read it — a row for the article in this shop and language, priced
     * or not.
     */
    private function visibleIn(int $article, int $shopId, string $language): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_product_visibility WHERE pid = ? AND shop = ? AND language = ?',
            [$article, $shopId, $language],
        );
    }

    public function unsubscribe(int $article, int $memberId): void
    {
        $this->connection->delete('tl_consho_stock_notice', ['article' => $article, 'member' => $memberId]);
    }

    public function isSubscribed(int $article, int $memberId): bool
    {
        return $memberId > 0 && false !== $this->connection->fetchOne(
            'SELECT 1 FROM tl_consho_stock_notice WHERE article = ? AND member = ?',
            [$article, $memberId],
        );
    }
}
