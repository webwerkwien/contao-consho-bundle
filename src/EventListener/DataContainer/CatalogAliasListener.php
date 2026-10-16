<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasScope;
use Webwerkwien\ContaoConshoBundle\Seo\RedirectRecorder;

/**
 * Fills a catalogue alias when the editor leaves the field empty.
 *
 * Four tables need one, for two different addresses:
 *
 * | manufacturer, product type | may appear in the product path template, Seo\ProductPathTemplate |
 * | property group, option     | ARE the filter address — "colour:red", Seo\FilterStateCodec       |
 *
 * In both cases the alias exists so that a corrected title does not move an
 * address. That is the difference to slugifying a title at render time, and it
 * is why Shopware's URLs follow a rename while ours do not.
 *
 * ⚠️ Like ProductAliasListener, this reads through DC_Table::getActiveRecord(),
 * the stored row merged with the values submitted so far. On a newly created
 * record the stored title is still empty, because Contao collects every field
 * and writes them in one UPDATE at the end of the request. It only works
 * because `title` stands before `alias` in both palettes and has therefore
 * already been processed.
 *
 * A changed manufacturer or product type alias moves the address of every
 * product of it where the pattern has the placeholder. The old addresses are
 * handed to RedirectRecorder here — see rememberOldAddresses().
 */
class CatalogAliasListener
{
    public function __construct(
        private readonly AliasFactory $aliases,
        private readonly RedirectRecorder $recorder,
    ) {
    }

    #[AsCallback(table: 'tl_consho_manufacturer', target: 'fields.alias.save')]
    public function onManufacturer(mixed $value, DC_Table $dc): string
    {
        $alias = $this->generate('tl_consho_manufacturer', $value, $dc);
        $this->rememberOldAddresses('tl_consho_manufacturer', $alias, $dc);

        return $alias;
    }

    #[AsCallback(table: 'tl_consho_product_type', target: 'fields.alias.save')]
    public function onProductType(mixed $value, DC_Table $dc): string
    {
        $alias = $this->generate('tl_consho_product_type', $value, $dc);
        $this->rememberOldAddresses('tl_consho_product_type', $alias, $dc);

        return $alias;
    }

    #[AsCallback(table: 'tl_consho_property_group', target: 'fields.alias.save')]
    public function onPropertyGroup(mixed $value, DC_Table $dc): string
    {
        return $this->generate('tl_consho_property_group', $value, $dc);
    }

    /**
     * Scoped to its group, not to the table.
     *
     * A filter state spells an option "colour:red", so the group is already
     * part of the address — "rot" may exist once per group. Without the scope
     * the second one would become "rot-2", a worse address for no gain.
     */
    #[AsCallback(table: 'tl_consho_property_option', target: 'fields.alias.save')]
    public function onPropertyOption(mixed $value, DC_Table $dc): string
    {
        $record = $dc->getActiveRecord();

        return $this->aliases->forRecord(
            'tl_consho_property_option',
            (string) $value,
            (string) ($record['title'] ?? ''),
            (int) $dc->id,
            new AliasScope('pid', (int) ($record['pid'] ?? 0)),
        );
    }

    /**
     * 🔴 Runs BEFORE the new alias is stored — the same reasoning as
     * ProductAliasListener::rememberOldAddress(): Contao writes all submitted
     * fields in one UPDATE after the save callbacks, so the stored alias is
     * still the previous one.
     *
     * Unlike there, an empty stored alias is not skipped: products of a
     * manufacturer without alias had an address without that segment, and it
     * moves now. A new record has no products, so nothing is found.
     */
    private function rememberOldAddresses(string $table, string $alias, DC_Table $dc): void
    {
        if ((string) ($dc->getCurrentRecord()['alias'] ?? '') === $alias) {
            return;
        }

        $this->recorder->recordSegmentAlias($table, (int) $dc->id, $alias, time());
    }

    private function generate(string $table, mixed $value, DC_Table $dc): string
    {
        return $this->aliases->forRecord(
            $table,
            (string) $value,
            (string) ($dc->getActiveRecord()['title'] ?? ''),
            (int) $dc->id,
        );
    }
}
