<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductProperties;

/**
 * Assigns property values to a product — as description, and as variant axis.
 *
 * Block A created `tl_consho_product_property` and `tl_consho_product_option`,
 * their models and their DCA files, but nothing that could put a row in either:
 * the product palette had no field for them and there is no back end module.
 * B-3 added them, when the filter machinery had nothing to filter on.
 *
 * ── Two fields, because they are two different statements ─────────────────
 *
 * | `properties` | what the article IS — "Bio", "Kräuterbitter". Filter information |
 * | `variantOptions` | what makes THIS ROW a particular variant — "250 ml" |
 *
 * Block A keeps them in separate tables on purpose: a forgotten `WHERE role =
 * 'variant'` returns wrong data silently, with no error, just a product whose
 * variants are not variants. Two fields keep that visible in the mask too.
 *
 * ── Neither field has an `sql` key ────────────────────────────────────────
 *
 * There is no such column; the save callbacks write the join tables and return
 * null. `doNotSaveEmpty` is what stops DC_Table adding the name to its UPDATE —
 * every field it collects goes into that statement by name, and a name without
 * a column is an SQL error. Same construction as `shops`, see
 * ProductShopsListener.
 */
class ProductPropertiesListener
{
    /**
     * The writes themselves (CW-4), shared with console commands. Built here
     * rather than injected, so the mask's tests keep constructing the listener
     * as before — they are the proof that the mask did not change.
     */
    private readonly ProductProperties $properties;

    public function __construct(private readonly Connection $connection)
    {
        $this->properties = new ProductProperties($connection);
    }

    /**
     * Every property value there is, grouped by its group.
     *
     * A flat list stops being usable at about thirty entries, and a shop with
     * six dimensions passes that on day one.
     *
     * @return array<string, array<int, string>>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.properties.options')]
    #[AsCallback(table: 'tl_consho_product', target: 'fields.variantOptions.options')]
    public function options(DataContainer|null $dc = null): array
    {
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT o.id AS id, o.title AS title, g.title AS groupTitle
                FROM tl_consho_property_option o
                JOIN tl_consho_property_group g ON g.id = o.pid
                ORDER BY g.title, o.title
                SQL,
        );

        $options = [];

        foreach ($rows as $row) {
            $options[(string) $row['groupTitle']][(int) $row['id']] = (string) $row['title'];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.properties.load')]
    public function loadProperties(mixed $value, DataContainer $dc): array
    {
        return array_map(strval(...), $this->properties->propertiesOf((int) $dc->id));
    }

    /**
     * @return list<string>
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.variantOptions.load')]
    public function loadVariantOptions(mixed $value, DataContainer $dc): array
    {
        return array_map(strval(...), $this->properties->variantOptionsOf((int) $dc->id));
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.properties.save')]
    public function saveProperties(mixed $value, DataContainer $dc): null
    {
        $this->properties->assignProperties((int) $dc->id, self::ids($value));

        return null;
    }

    /**
     * A sister with the same values is refused (ProductProperties), shown at
     * the field.
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.variantOptions.save')]
    public function saveVariantOptions(mixed $value, DataContainer $dc): null
    {
        $this->properties->assignVariantOptions((int) $dc->id, self::ids($value));

        return null;
    }

    /**
     * @return list<int>
     */
    private static function ids(mixed $value): array
    {
        return array_values(array_map(intval(...), array_filter(StringUtil::deserialize($value, true), is_scalar(...))));
    }
}
