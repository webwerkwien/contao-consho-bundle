<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * What a verified webhook is about (WebhookPaymentHandlerInterface): the
 * provider reference of an attempt of the method, or nothing this
 * installation has to act on.
 */
final class WebhookReference
{
    private function __construct(
        public readonly string $reference,
        /**
         * Fallback for a charge whose reference never came back (P94): the
         * attempt the provider's metadata names. Taken only for an attempt of
         * the same method that carries no reference yet.
         */
        public readonly int $attemptId,
        public readonly string $ignoredBecause,
    ) {
    }

    public static function about(string $reference, int $attemptId = 0): self
    {
        $reference = trim($reference);

        if ('' === $reference && $attemptId <= 0) {
            throw new \InvalidArgumentException('A webhook is about a provider reference or an attempt.');
        }

        return new self($reference, max(0, $attemptId), '');
    }

    /**
     * Genuine, but nothing to do here: an event type without a payment, or
     * one for an order another installation created.
     */
    public static function ignored(string $because): self
    {
        $because = trim($because);

        return new self('', 0, '' === $because ? 'ignored' : $because);
    }

    public function isIgnored(): bool
    {
        return '' !== $this->ignoredBecause;
    }
}
