<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

/**
 * One thing a module delivered for an entitlement, as the order page shows it
 * (block licence, LZ-1): a text to copy — a licence key —, a download, or
 * both, and optionally buttons the customer may press ("release the
 * installation", "issue a new key"): the first as `action`, further ones in
 * `moreActions`.
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
        /** @var array<string, string> further actions, name => caption */
        public readonly array $moreActions = [],
    ) {
        if (1 !== preg_match(self::ID, $id)) {
            throw new \InvalidArgumentException(\sprintf('A delivered item needs an id of letters, digits, ".", "_" or "-", not "%s".', $id));
        }

        foreach ([...(null === $action ? [] : [$action => $actionLabel]), ...$moreActions] as $name => $caption) {
            if (1 !== preg_match(self::ID, (string) $name) || null === $caption || '' === $caption) {
                throw new \InvalidArgumentException(\sprintf('The action of delivered item "%s" needs a name of letters, digits, ".", "_" or "-" and a caption.', $id));
            }
        }
    }

    public function offers(string $action): bool
    {
        return \array_key_exists($action, $this->actions());
    }

    /**
     * Every button, the first action first.
     *
     * @return array<string, string> name => caption
     */
    public function actions(): array
    {
        return (null === $this->action ? [] : [$this->action => (string) $this->actionLabel]) + $this->moreActions;
    }
}
