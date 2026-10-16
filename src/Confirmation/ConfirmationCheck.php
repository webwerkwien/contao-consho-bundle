<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Confirmation;

/**
 * What a confirmation link stands for, as far as EmailConfirmation can tell.
 *
 * Whether the person in front of the screen may use it — logged in as the
 * right account, say — is the caller's question: only the caller knows what
 * was being confirmed.
 */
final class ConfirmationCheck
{
    /**
     * @param array<string, list<int>> $related the records the confirmation was requested for
     */
    private function __construct(
        public readonly ConfirmationStatus $status,
        public readonly string $email = '',
        public readonly array $related = [],
    ) {
    }

    /**
     * @param array<string, list<int>> $related
     */
    public static function of(ConfirmationStatus $status, string $email, array $related): self
    {
        return new self($status, $email, $related);
    }

    public static function invalid(): self
    {
        return new self(ConfirmationStatus::Invalid);
    }

    /**
     * @return list<int>
     */
    public function ids(string $table): array
    {
        return $this->related[$table] ?? [];
    }
}
