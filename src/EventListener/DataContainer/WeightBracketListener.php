<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\PaletteManipulator;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Shipping\WeightRateShipping;

/**
 * The upper weight of a price row, only where it means something (E-4, V2).
 *
 * The price rows of every shipping method live in one table. `maxWeight`
 * matters to the weight brackets alone; at a flat rate it would be a field
 * that does nothing. So it is taken out of the palette unless the parent
 * method is a weight rate — and there the list shows and sorts by it.
 */
final class WeightBracketListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly MoneyFormatter $money = new MoneyFormatter(),
    ) {
    }

    #[AsCallback(table: 'tl_consho_shipping_price', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        if (WeightRateShipping::getType() === $this->parentType($dc)) {
            $GLOBALS['TL_DCA']['tl_consho_shipping_price']['list']['sorting']['fields'] = ['currency', 'maxWeight'];

            return;
        }

        PaletteManipulator::create()
            ->removeField('maxWeight')
            ->applyToPalette('default', 'tl_consho_shipping_price')
        ;
    }

    /**
     * The row in the list, formatted (E): the columns are
     * decimal(…,4), and MariaDB hands them out as "2.0000" (case 30).
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_shipping_price', target: 'list.label.label')]
    public function label(array $row): string
    {
        $language = (string) ($GLOBALS['TL_LANGUAGE'] ?? 'de');
        $net = $this->money->quantity(is_numeric($row['net'] ?? null) ? (string) $row['net'] : '0', $language);
        $weight = is_numeric($row['maxWeight'] ?? null) ? (string) $row['maxWeight'] : '0';
        $lang = $GLOBALS['TL_LANG']['tl_consho_shipping_price'] ?? [];

        if (!BigDecimal::of($weight)->isPositive()) {
            return \sprintf((string) ($lang['rowLabel'] ?? '%s %s netto'), (string) ($row['currency'] ?? ''), $net);
        }

        return \sprintf((string) ($lang['bracketLabel'] ?? '%s — bis %s kg: %s netto'), (string) ($row['currency'] ?? ''), $this->money->quantity($weight, $language), $net);
    }

    /**
     * In the list of a method's rows the id is the method's; editing one row,
     * it is the row's. `act` tells them apart.
     */
    private function parentType(DataContainer|null $dc): string
    {
        // Not `$dc->id ?? 0`: DataContainer has __get but no __isset, so `??`
        // asks isset() on a protected property and always gets 0.
        $id = null === $dc ? 0 : (int) $dc->id;

        if ($id < 1) {
            return '';
        }

        $act = (string) $this->requests->getCurrentRequest()?->query->get('act', '');

        $methodId = '' === $act || 'paste' === $act || 'select' === $act
            ? $id
            : (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_shipping_price WHERE id = ?', [$id]);

        return (string) $this->connection->fetchOne('SELECT type FROM tl_consho_shipping_method WHERE id = ?', [$methodId]);
    }
}
