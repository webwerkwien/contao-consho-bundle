<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

/**
 * One legal text as the checkout shows it and as C-7 will freeze it (C17).
 *
 * `checksum` is taken over the headline and the text AFTER the insert tags are
 * resolved — what the buyer actually read, not the source it was written as.
 * A `{{date}}` in the terms would otherwise produce a different checksum every
 * day while the agreement stayed the same, and the reverse case matters more:
 * a `{{link_url::42}}` that starts pointing somewhere else IS a change to what
 * was agreed, and has to show up.
 */
final readonly class LegalText
{
    public function __construct(
        public LegalTextKind $kind,
        public int $elementId,
        public int $pageId,
        public string $headline,
        public string $text,
        public string $checksum,
    ) {
    }

    public static function of(LegalTextKind $kind, int $elementId, int $pageId, string $headline, string $text): self
    {
        return new self(
            $kind,
            $elementId,
            $pageId,
            $headline,
            $text,
            hash('sha256', $headline."\0".$text),
        );
    }
}
