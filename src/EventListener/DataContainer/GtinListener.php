<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DC_Table;
use Webwerkwien\ContaoConshoBundle\Catalog\Gtin;

/**
 * Normalises the GTIN and refuses one that cannot be right.
 *
 * Stored without separators, because the number is a key: a lookup for
 * "4006381333931" must find the article somebody entered as "4 006381 333931".
 *
 * ⚠️ Refusing rather than accepting-and-reporting, unlike almost everything
 * else on the start page. A wrong GTIN is not an incomplete shop, it is a
 * statement about a different article — and the moment to catch it is while the
 * person who typed it is still looking at the field.
 */
class GtinListener
{
    #[AsCallback(table: 'tl_consho_product', target: 'fields.gtin.save')]
    public function __invoke(mixed $value, DC_Table $dc): string
    {
        $typed = trim((string) $value);

        if (!Gtin::isValid($typed)) {
            throw new \RuntimeException(
                sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['gtinInvalid'], $typed),
            );
        }

        return Gtin::normalise($typed);
    }
}
