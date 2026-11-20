<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDeclaration;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationResult;

/**
 * The customer's answer to a price offer, recorded (Abo-10, P119): accepted,
 * or declined — then with the cancellation it made (P121), to be confirmed
 * after the commit like any other. `mailed` says whether the confirmation
 * went out — the page must not claim a mail that failed.
 */
final class PriceOfferAnswered
{
    public function __construct(
        public readonly PriceOfferDue $offer,
        public readonly bool $accepted,
        public readonly CancellationDeclaration|null $declaration = null,
        public readonly CancellationResult|null $cancellation = null,
        public readonly bool $mailed = false,
    ) {
    }

    public function mailed(): self
    {
        return new self($this->offer, $this->accepted, $this->declaration, $this->cancellation, true);
    }
}
