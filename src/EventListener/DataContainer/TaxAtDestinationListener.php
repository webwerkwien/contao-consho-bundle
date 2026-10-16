<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\Message;
use Doctrine\DBAL\Connection;
use Webwerkwien\ContaoConshoBundle\Tax\ShopVatId;

/**
 * "Steuer im Bestimmungsland" belongs to the business, not the shop (block R,
 * O3, R1): two shops of one business cannot tax the same sale differently,
 * and the OSS threshold counts them together. Consho has no entity above the
 * shop, so the shop's own VAT ID stands for the business — saving the switch
 * on one shop sets it on every shop with the same ID, and says so.
 *
 * A shop without a VAT ID stands for itself.
 *
 * Written past the mask for the other shops: no version of them is kept.
 * The value is one checkbox the operator just chose, and the message names
 * every shop it went to.
 */
class TaxAtDestinationListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ContaoFramework $framework,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'config.onsubmit')]
    public function __invoke(DataContainer $dc): void
    {
        $this->spread((int) $dc->id, time());
    }

    /**
     * @return list<string> the titles of the shops that were changed
     */
    public function spread(int $shopId, int $now): array
    {
        $shops = $this->connection->fetchAllAssociative('SELECT id, title, vatId, taxAtDestination FROM tl_consho_shop');
        $own = null;

        foreach ($shops as $shop) {
            if ((int) $shop['id'] === $shopId) {
                $own = $shop;
            }
        }

        $key = null === $own ? '' : ShopVatId::key((string) $own['vatId']);

        if (null === $own || '' === $key) {
            return [];
        }

        $value = (bool) $own['taxAtDestination'];
        $changed = [];

        foreach ($shops as $shop) {
            if ((int) $shop['id'] === $shopId || ShopVatId::key((string) $shop['vatId']) !== $key || (bool) $shop['taxAtDestination'] === $value) {
                continue;
            }

            $this->connection->update('tl_consho_shop', ['taxAtDestination' => $value ? 1 : 0, 'tstamp' => $now], ['id' => (int) $shop['id']]);
            $changed[] = (string) $shop['title'];
        }

        if ([] !== $changed) {
            $lang = $GLOBALS['TL_LANG']['tl_consho_shop'] ?? [];

            $this->framework->getAdapter(Message::class)->addConfirmation(\sprintf(
                (string) ($lang['taxAtDestinationShared'] ?? '%s: %s'),
                (string) ($lang[$value ? 'taxAtDestinationOn' : 'taxAtDestinationOff'] ?? ($value ? 'on' : 'off')),
                implode(', ', $changed),
            ));
        }

        return $changed;
    }
}
