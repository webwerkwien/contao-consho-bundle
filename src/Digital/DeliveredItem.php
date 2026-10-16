<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

/**
 * One thing a module delivered for an entitlement, as the order page shows it
 * (block licence, LZ-1): a text to copy — a licence key —, a download, or
 * both, and optionally one button the customer may press ("release the
 * installation").
 *
 * The id names the item within its entitlement and comes back with the
 * download and the action; it stays the same as long as the item exists. The
 * page never puts the text into a mail (E3) and shows nothing of it once the
 * entitlement is no longer usable (E4) — that is the core's, not the module's.
 */
final class DeliveredItem
{
    private const ID = '/^[A-Za-z0-9._-]{1,64}$/';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string|null $text = null,
        // Offered as a button; the file comes from download() of the module.
        public readonly bool $download = false,
        // The action passed to act(), and the button's caption.
        public readonly string|null $action = null,
        public readonly string|null $actionLabel = null,
    ) {
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException(\sprintf('A delivered item needs an id of letters, digits, ".", "_" or "-", not "%s".', $id));
        }

        if (null !== $action && (1 !== preg_match(self::ID, $action) || null === $actionLabel || '' === $actionLabel)) {
            throw new \InvalidArgumentException(\sprintf('The action of delivered item "%s" needs a name of letters, digits, ".", "_" or "-" and a caption.', $id));
        }
    }

    public function offers(string $action): bool
    {
        return null !== $this->action && $action === $this->action;
    }
}
