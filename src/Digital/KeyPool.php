<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;

/**
 * Fills the key pool of an article (F-6, F19) — for the mask's "Schlüssel
 * einfügen" and for a command (CLI coverage K2), so both follow one rule.
 *
 * One code per line, trimmed, at most 255 characters; empty lines and a code
 * already in this article's pool are skipped, so pasting the same list twice
 * does not sell a code twice. The pool belongs to the article: a variant
 * keeps its own.
 *
 * The answer is counts only. The codes are what a buyer pays for — whoever
 * passes them on decides where they appear, and nothing here repeats them.
 */
class KeyPool
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{added: int, skipped: int, left: int} left: codes still free
     *                                                    in this article's pool
     */
    public function add(int $productId, string $codes, int $now): array
    {
        $existing = array_flip(array_map(strval(...), $this->connection->fetchFirstColumn('SELECT code FROM tl_consho_key WHERE pid = ?', [$productId])));
        $added = 0;
        $skipped = 0;

        foreach (preg_split('/\R/u', $codes) ?: [] as $line) {
            $code = mb_substr(trim($line), 0, 255);

            if ('' === $code) {
                continue;
            }

            if (isset($existing[$code])) {
                ++$skipped;

                continue;
            }

            $this->connection->insert('tl_consho_key', ['pid' => $productId, 'tstamp' => $now, 'code' => $code]);
            $existing[$code] = true;
            ++$added;
        }

        return ['added' => $added, 'skipped' => $skipped, 'left' => $this->free($productId)];
    }

    /**
     * Codes not yet handed out.
     */
    public function free(int $productId): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM tl_consho_key WHERE pid = ? AND entitlement = 0', [$productId]);
    }
}
