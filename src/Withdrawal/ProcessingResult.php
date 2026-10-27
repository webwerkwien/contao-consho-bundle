<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

/**
 * What an action on the page "Widerruf bearbeiten" came to: a key of
 * $GLOBALS['TL_LANG']['tl_consho_withdrawal']['result'] and its parameters.
 *
 * Amounts and quantities stay plain decimals here ("11.25"); `$kinds` says
 * which parameters they are, and the page formats them in the language of
 * the backend user — this class does not know it.
 */
final class ProcessingResult
{
    /** An amount in the order's currency. */
    public const MONEY = 'money';

    /** A quantity, shown without trailing zeros. */
    public const QUANTITY = 'quantity';

    /**
     * @param list<string>                                 $params
     * @param array<int, self::MONEY|self::QUANTITY> $kinds  by position in $params
     */
    private function __construct(
        public readonly bool $done,
        public readonly string $key,
        public readonly array $params = [],
        public readonly array $kinds = [],
    ) {
    }

    /**
     * @param list<string> $params
     */
    public static function done(string $key, array $params = []): self
    {
        return new self(true, $key, $params);
    }

    /**
     * @param list<string>                                 $params
     * @param array<int, self::MONEY|self::QUANTITY> $kinds  by position in $params
     */
    public static function refused(string $key, array $params = [], array $kinds = []): self
    {
        return new self(false, $key, $params, $kinds);
    }
}
