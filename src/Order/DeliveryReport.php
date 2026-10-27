<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The outcome of "Als versandt melden" — what the back end tells the operator.
 */
final class DeliveryReport
{
    /**
     * @param list<string> $tooMuch the items that exceed what is still open
     */
    private function __construct(
        public readonly string $outcome,
        public readonly bool $mailSent = false,
        public readonly array $tooMuch = [],
        public readonly int|null $toArchive = null,
    ) {
    }

    /**
     * @param int|null $toArchive an invoice issued inside the caller's transaction, whose PDF the caller archives after its own commit (Abo-4)
     */
    public static function reported(bool $mailSent, int|null $toArchive = null): self
    {
        return new self('reported', $mailSent, [], $toArchive);
    }

    public static function alreadyReported(): self
    {
        return new self('alreadyReported');
    }

    public static function empty(): self
    {
        return new self('empty');
    }

    /**
     * @param list<string> $names
     */
    public static function tooMuch(array $names): self
    {
        return new self('tooMuch', false, $names);
    }

    public static function unknown(): self
    {
        return new self('unknown');
    }
}
