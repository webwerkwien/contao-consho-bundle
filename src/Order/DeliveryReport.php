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
        // A replacement delivery (RK-5): its mail is the replacement mail, not the shipping mail.
        public readonly bool $replacement = false,
    ) {
    }

    /**
     * @param int|null $toArchive an invoice issued inside the caller's transaction, whose PDF the caller archives after its own commit (Abo-4)
     */
    public static function reported(bool $mailSent, int|null $toArchive = null): self
    {
        return new self('reported', $mailSent, [], $toArchive);
    }

    /**
     * A replacement delivery reported (RK-5); the outcome is "reported" like
     * any delivery's.
     */
    public static function reportedReplacement(bool $mailSent): self
    {
        return new self('reported', $mailSent, [], null, true);
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

    /**
     * A replacement delivery carrying more than its complaint still has to
     * replace (RK-5, S2).
     *
     * @param list<string> $names
     */
    public static function tooMuchForComplaint(array $names): self
    {
        return new self('tooMuchForComplaint', false, $names);
    }

    /**
     * A replacement delivery whose goods are short in stock and not on
     * backorder (RK-5, S4) — nothing was booked, nothing reported.
     *
     * @param list<string> $names
     */
    public static function outOfStock(array $names): self
    {
        return new self('outOfStock', false, $names);
    }

    /**
     * A replacement delivery whose complaint is no longer decided as a
     * replacement, or already closed.
     */
    public static function notReplacement(): self
    {
        return new self('notReplacement');
    }
}
