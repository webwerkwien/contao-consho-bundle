<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * What start() decided (spec 6.2): done here with a payment notice, off to the
 * provider, or failed. Named constructors keep a redirect from lacking its URL.
 *
 * A completed start carries two different kinds of text. The notice is the
 * method's payment notice as Contao rich text — HTML from a tinyMCE field,
 * entered by the operator — and is printed like any other Contao rich text.
 * The details are plain text strings for the template to escape; formatting
 * a bank connection is the template's business, not the handler's.
 *
 * A redirect names the provider's reference of what it set up (a session, an
 * order, a payment). The core keeps it on the attempt and accepts only
 * notifications about it (NotificationResult). It has to be this attempt's
 * own — an order number shared by several attempts would let a notification
 * about one settle another.
 */
final class PaymentStart
{
    /**
     * @param array<string, string> $details
     */
    private function __construct(
        public readonly PaymentStartOutcome $outcome,
        public readonly string $notice = '',
        public readonly array $details = [],
        public readonly string $url = '',
        public readonly string $reason = '',
        public readonly string $reference = '',
    ) {
    }

    /**
     * @param array<string, string> $details
     */
    public static function completed(string $notice, array $details = []): self
    {
        return new self(PaymentStartOutcome::Completed, notice: $notice, details: $details);
    }

    public static function redirect(string $url, string $reference): self
    {
        if ('' === $url) {
            throw new \InvalidArgumentException('A redirect needs a URL.');
        }

        if ('' === trim($reference)) {
            throw new \InvalidArgumentException('A redirect needs the provider reference of what it set up.');
        }

        return new self(PaymentStartOutcome::Redirect, url: $url, reference: trim($reference));
    }

    public static function failed(string $reason): self
    {
        return new self(PaymentStartOutcome::Failed, reason: $reason);
    }
}
