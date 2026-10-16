<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * One custom field definition (tl_consho_custom_field), as a product type
 * assigns it (block A 3.1). The alias is the key of its value in the
 * product's JSON column.
 */
final class CustomField
{
    public const TEXT = 'text';

    public const NUMBER = 'number';

    public const DATE = 'date';

    public const BOOLEAN = 'boolean';

    public const TYPES = [self::TEXT, self::NUMBER, self::DATE, self::BOOLEAN];

    public function __construct(
        public readonly int $id,
        public readonly string $alias,
        public readonly string $title,
        public readonly string $type,
    ) {
    }
}
