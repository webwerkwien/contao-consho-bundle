<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use Webwerkwien\ContaoConshoBundle\Catalog\AliasFactory;

/**
 * Fills tl_consho_product.alias when the editor leaves it empty.
 *
 * The field is not mandatory, so without this nothing complains and the product
 * is simply saved without an address it could ever be called under. Contao does
 * the same for news and events.
 *
 * Two deliberate differences to the core implementation:
 *
 * 1. Contao reads the title from `$dc->activeRecord`, deprecated since 5.0.
 *    Contao 5.7 announces its removal for Contao 6, but 6.0.0 pushed that back
 *    to Contao 7 — so this is a choice, not a deadline. It is still the better
 *    one, because both documented successors exist in 5.7 and in 6.0.0 and no
 *    version switch is needed:
 *
 *    - `DataContainer::getCurrentRecord()` returns the stored row.
 *    - `DC_Table::getActiveRecord()` returns the stored row merged with the
 *      values submitted so far.
 *
 *    A save callback needs the second one. Contao collects every field in
 *    `arrSubmit` and writes them in a single UPDATE at the end of the request,
 *    so the stored title is still empty on a newly created product.
 *
 * 2. That merge only works because `title` stands before `alias` in the palette
 *    and has therefore already been processed. Moving `alias` ahead of `title`
 *    would silently break alias generation — hence this note rather than a
 *    defensive fallback that would hide the mistake.
 *
 * The generating itself moved to Catalog\AliasFactory in B-1, when manufacturer
 * and product type needed the same twenty lines. Three copies is where the
 * numeric check quietly goes missing from one of them.
 *
 * Since the alias is part of the product address, a changed alias moves that
 * address. It is NOT recorded here but in ProductAddressListener, once for the
 * whole save: this callback cannot know the manufacturer or sku submitted after
 * it, and with several parts changing at once it would compare against an
 * address that is half new.
 */
class ProductAliasListener
{
    public function __construct(private readonly AliasFactory $aliases)
    {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.alias.save')]
    public function __invoke(mixed $value, DC_Table $dc): string
    {
        return $this->aliases->forRecord(
            'tl_consho_product',
            (string) $value,
            (string) ($dc->getActiveRecord()['title'] ?? ''),
            (int) $dc->id,
        );
    }
}
