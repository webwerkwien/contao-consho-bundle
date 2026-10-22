<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * A path template the operator typed that cannot produce usable addresses.
 *
 * Thrown while saving, never while rendering. A template that reaches the front
 * end has already been accepted — if one could fail there, it would not fail
 * for one page but for every product address in the shop at once.
 *
 * Carries a language key rather than a finished sentence: the class is used by
 * the route builder too, where there is no back end and no translation, and the
 * operator-facing wording belongs in the language files like every other
 * refusal in this bundle.
 */
final class InvalidPathTemplate extends \InvalidArgumentException
{
    public const UNKNOWN_PLACEHOLDER = 'pathTemplateUnknownPlaceholder';
    public const NO_IDENTIFIER = 'pathTemplateNoIdentifier';
    public const REPEATED_PLACEHOLDER = 'pathTemplateRepeated';
    public const EMPTY = 'pathTemplateEmpty';

    private function __construct(
        public readonly string $key,
        public readonly string $context,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * @param list<string> $known
     */
    public static function unknownPlaceholder(string $name, array $known): self
    {
        return new self(self::UNKNOWN_PLACEHOLDER, $name, \sprintf(
            'Unknown placeholder "%s". Available: %s.',
            $name,
            implode(', ', array_map(static fn (string $p): string => '{'.$p.'}', $known)),
        ));
    }

    public static function cannotIdentifyAProduct(): self
    {
        return new self(
            self::NO_IDENTIFIER,
            '',
            'A path template needs {alias}; without it, products would share an address.',
        );
    }

    public static function repeatedPlaceholder(string $name): self
    {
        return new self(
            self::REPEATED_PLACEHOLDER,
            $name,
            \sprintf('The placeholder {%s} appears more than once.', $name),
        );
    }

    public static function isEmpty(): self
    {
        return new self(self::EMPTY, '', 'A path template cannot be empty.');
    }
}
