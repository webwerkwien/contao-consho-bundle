<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationVersioning;

/**
 * The two Contao callbacks that hand out the parent record's version number —
 * Block M, 7.4.
 *
 * Both fire from Contao\Versions itself, not from DC_Table. Every version of
 * these tables therefore carries the translations, however it came about: the
 * edit mask, "edit multiple", the toggle, the alias batch action
 * (AliasBatchListener), the initial version created when a record is opened.
 *
 * One attribute per table in TranslatableFields::tables() — a test holds the
 * two lists together.
 */
class TranslationVersionListener
{
    public function __construct(private readonly TranslationVersioning $versioning)
    {
    }

    /**
     * The version number comes from a database result and, on restore, from
     * Input::post() — a string. Accepted as such rather than trusting the
     * coercion of a caller that is not under strict_types.
     *
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_property_group', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_payment_method', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_shop', target: 'config.oncreate_version')]
    #[AsCallback(table: 'tl_consho_custom_field', target: 'config.oncreate_version')]
    public function onCreate(string $table, int|string $pid, int|string $version, array $data): void
    {
        $this->versioning->store($table, (int) $pid, (int) $version);
    }

    /**
     * $data is of no use here: Versions::restore() has already reduced it to
     * the parent table's columns. TranslationVersioning reads the row itself.
     *
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_product_type', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_manufacturer', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_property_group', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_property_option', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_shipping_method', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_payment_method', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_shop', target: 'config.onrestore_version')]
    #[AsCallback(table: 'tl_consho_custom_field', target: 'config.onrestore_version')]
    public function onRestore(string $table, int|string $pid, int|string $version, array $data): void
    {
        $this->versioning->restore($table, (int) $pid, (int) $version);
    }
}
