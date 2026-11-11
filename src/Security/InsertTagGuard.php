<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Security;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Customer input with an insert tag in it is refused, in every field of every
 * Consho form (spec C, open points → Technik).
 *
 * The forms read their fields from the request, not through Input::post(),
 * so Contao's own encodeInsertTags() never touches them. Consho prints the
 * values where Contao does not replace insert tags — an order placed with
 * `{{date}}` in the company name shows the tag as typed in the checkout, the
 * invoice and the mail. That output is the protection; this is the second
 * line, for a template that one day prints a value through a filter that
 * does replace them.
 *
 * Refused with a message, never stripped: an invoice must not show something
 * other than what was typed. Nobody is locked out — a guest has no account to
 * lock, a new address gets round any block, and the customers most likely to
 * type an insert tag are Contao developers asking a question. Each attempt is
 * logged instead, with the form and the field names but not the input, so an
 * operator can see whether someone is probing the shop.
 *
 * `{{` and `}}` alone: an insert tag needs both braces doubled, and a single
 * brace is ordinary text.
 */
final class InsertTagGuard
{
    /**
     * Field names go into the log only in the shape a Consho form gives them,
     * and only a few: some forms pass every posted key, and a key is whatever
     * the sender makes of it.
     */
    private const LOGGABLE_FIELD = '/^[A-Za-z0-9_]{1,40}\z/';

    private const LOGGED_FIELDS = 5;

    public function __construct(
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public static function contains(string $value): bool
    {
        return str_contains($value, '{{') || str_contains($value, '}}');
    }

    /**
     * The names of the fields holding an insert tag. Values that are no
     * string (a list of quantities, say) are no free text and are skipped.
     *
     * @param array<array-key, mixed> $values
     *
     * @return list<string>
     */
    public static function fieldsWith(array $values): array
    {
        $fields = [];

        foreach ($values as $field => $value) {
            if (\is_string($value) && self::contains($value)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    /**
     * Whether the form has to be refused; logs the attempt when it does.
     *
     * @param array<array-key, mixed> $values
     */
    public function refuses(string $form, array $values): bool
    {
        $fields = self::fieldsWith($values);

        if ([] === $fields) {
            return false;
        }

        $this->logger->warning(\sprintf('Insert tag in customer input refused (form "%s", fields %s)', $form, self::loggable($fields)));

        return true;
    }

    /**
     * @param list<string> $fields
     */
    private static function loggable(array $fields): string
    {
        $named = array_values(array_filter($fields, static fn (string $field): bool => 1 === preg_match(self::LOGGABLE_FIELD, $field)));
        $shown = \array_slice($named, 0, self::LOGGED_FIELDS);
        $others = \count($fields) - \count($shown);

        if ($others > 0) {
            $shown[] = \sprintf('%d other', $others);
        }

        return implode(', ', $shown);
    }
}
