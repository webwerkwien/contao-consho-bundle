<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Doctrine\DBAL\Connection;

/**
 * The supplier at the product (Block G, G-7, E5 a): the names already in use
 * as suggestions, and a spelling variant of one of them saved as that one.
 *
 * "mein lieferant " and "Mein  Lieferant" are the same name — case and
 * spaces — and are saved as the name already in use. "Mein Lieferant KG" is
 * not: no rule can tell a legal form from another supplier. The suggestion
 * list makes it visible, and the Lagerstand page filters by supplier.
 */
final class SupplierListener
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.supplier.options')]
    public function options(): array
    {
        return array_map(
            strval(...),
            $this->connection->fetchFirstColumn("SELECT DISTINCT supplier FROM tl_consho_product WHERE supplier <> '' ORDER BY supplier"),
        );
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.supplier.save')]
    public function save(mixed $value): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', \is_scalar($value) ? (string) $value : ''));

        if ('' === $name) {
            return '';
        }

        foreach ($this->options() as $known) {
            if (self::key($known) === self::key($name)) {
                return $known;
            }
        }

        return $name;
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
