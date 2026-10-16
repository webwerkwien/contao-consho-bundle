<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

/**
 * What an action on the page "Widerruf bearbeiten" came to: a key of
 * $GLOBALS['TL_LANG']['tl_consho_withdrawal']['result'] and its parameters.
 */
final class ProcessingResult
{
    /**
     * @param list<string> $params
     */
    private function __construct(
        public readonly bool $done,
        public readonly string $key,
        public readonly array $params = [],
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
     * @param list<string> $params
     */
    public static function refused(string $key, array $params = []): self
    {
        return new self(false, $key, $params);
    }
}
