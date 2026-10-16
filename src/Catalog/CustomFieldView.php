<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One custom field as the product page shows it (ZW-4): the title in the
 * page's language, the stored value, and the value formatted for that
 * language. Yes/no keeps `formatted` empty and says `yes` — the template
 * translates the word, as it does every other label.
 */
final class CustomFieldView
{
    public function __construct(
        public readonly string $alias,
        public readonly string $title,
        public readonly string $type,
        public readonly string $value,
        public readonly string $formatted,
        public readonly bool $yes,
    ) {
    }
}
