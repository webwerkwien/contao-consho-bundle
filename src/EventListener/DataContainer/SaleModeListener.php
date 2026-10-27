<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;

/**
 * The legend "Auslieferung und Gültigkeit" by the product's kind and level.
 *
 * ── Kind (Dienstleistung, DL5) ──────────────────────────────────────────
 *
 * The kind of the product TYPE, by the rule of DbalArticleShippingData — the
 * main product's type for a variant, no type at all counts as physical:
 *
 *  - physical: the whole legend goes; a parcel has no delivery, term or
 *    subscription.
 *  - service: nothing is delivered, so delivery type, file and keys go; term
 *    and sale modes stay (a gym membership is a subscription).
 *  - digital: everything.
 *
 * The type is chosen in the same mask, so it submits on change and the
 * palette follows at once rather than after the next save.
 *
 * ── Level (Abo-1, P2) ───────────────────────────────────────────────────
 *
 * Sale modes and rules only at the main product: SaleModes reads them from
 * there, and shown at a variant they would be fields that do nothing — the
 * trap K8 of block F described for the type. The variant keeps its own term,
 * though: entitlementMonths is a subpalette of saleOnce, so it goes back into
 * the palette in the ticks' place (DigitalProductSettings reads a variant's
 * own months first).
 */
final class SaleModeListener
{
    private const MAIN_ONLY = 'saleOnce,saleSubscription';

    private const DELIVERY = ['digitalDelivery', 'digitalFile', 'keyImport'];

    private const SHIPPING = ['weight', 'shippingWeight', 'length', 'width', 'height'];

    // In the sales legend, not one of its own: the mask has no room for a
    // ninth tab (SaleModeDcaTest).
    private const VOUCHER = ',voucherValue,voucherValidMonths,voucherSinglePurpose';

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly ArticleShippingDataInterface $articles,
    ) {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        $id = $this->editedId($dc);

        if ($id < 1) {
            return;
        }

        $palette = (string) ($GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'] ?? '');
        $kind = ProductKind::stored($this->articles->of([$id])[$id]['kind'] ?? null);
        $variant = $this->isVariant($id);
        $unused = $this->filledButUnused($id, $kind, $variant);

        // A voucher has no delivery fields either (WG3): it becomes a code.
        if ($kind->isPhysical() || $kind->isVoucher()) {
            $palette = (string) preg_replace('/\{digital_legend[^}]*\}[^;]*;?/', [] === $unused ? '' : '{digital_legend},'.implode(',', $unused).';', $palette);
        } elseif (ProductKind::Service === $kind) {
            $hidden = array_diff(self::DELIVERY, $unused);
            $palette = [] === $hidden ? $palette : (string) preg_replace('/,?\b(?:'.implode('|', $hidden).')\b/', '', $palette);
            $palette = str_replace('},,', '},', $palette);
        }

        // Weight and dimensions only for goods (spec Wertgutscheine, open
        // points): nothing else is packed. Filled ones stay and say so.
        if (!$kind->isPhysical()) {
            $shipping = $this->shippingFilled($id);
            $palette = (string) preg_replace('/\{shipping_legend[^}]*\}[^;]*;?/', [] === $shipping ? '' : '{shipping_legend},'.implode(',', $shipping).';', $palette);
            $unused = [...$unused, ...$shipping];
        }

        if ($variant) {
            $palette = str_replace(self::MAIN_ONLY, 'entitlementMonths', $palette);
        }

        // WG3: value and validity for the kind "Wertgutschein" only; the
        // validity at the main product, like entitlementMonths.
        if (!$kind->isVoucher()) {
            $palette = str_replace(self::VOUCHER, '', $palette);
        } elseif ($variant) {
            $palette = str_replace([',voucherValidMonths', ',voucherSinglePurpose'], '', $palette);
        }

        $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'] = $palette;
        $this->markUnused($unused, $kind);
    }

    /**
     * Fields the kind does not use but that hold a value (DL): they stay in the mask, or the finding "Produkt passt nicht zu seiner
     * Art" (ProductKindMismatchIssueSource) asks for something nobody can
     * reach. Emptied, they disappear with the next load. Keys are emptied in
     * the key list, so their field stays while any are in the pool.
     *
     * @return list<string>
     */
    private function filledButUnused(int $id, ProductKind $kind, bool $variant): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT digitalDelivery, digitalFile, saleSubscription, (SELECT COUNT(*) FROM tl_consho_key k WHERE k.pid = p.id) AS keyCount
               FROM tl_consho_product p WHERE p.id = ?',
            [$id],
        );

        if (false === $row || ProductKind::Digital === $kind) {
            return [];
        }

        $unused = [];

        if ('' !== (string) $row['digitalDelivery']) {
            $unused[] = 'digitalDelivery';
        }

        if (null !== $row['digitalFile'] && '' !== (string) $row['digitalFile']) {
            $unused[] = 'digitalFile';
        }

        if ((int) $row['keyCount'] > 0) {
            $unused[] = 'keyImport';
        }

        // Sold as a subscription only at the main product (SaleModes).
        if (!$kind->allowsSubscription() && !$variant && (bool) $row['saleSubscription']) {
            $unused[] = 'saleSubscription';
        }

        return $unused;
    }

    /**
     * The shipping fields of a product that is not goods but holds a value.
     *
     * @return list<string>
     */
    private function shippingFilled(int $id): array
    {
        $row = $this->connection->fetchAssociative('SELECT '.implode(', ', self::SHIPPING).' FROM tl_consho_product WHERE id = ?', [$id]);

        if (false === $row) {
            return [];
        }

        return array_values(array_filter(self::SHIPPING, static fn (string $field): bool => is_numeric($row[$field]) && 0.0 !== (float) $row[$field]));
    }

    /**
     * Says at each such field that it is not used, in front of its help.
     *
     * @param list<string> $fields
     */
    private function markUnused(array $fields, ProductKind $kind): void
    {
        $lang = &$GLOBALS['TL_LANG']['tl_consho_product'];
        $kindLabel = (string) ($GLOBALS['TL_LANG']['CONSHO']['kindMismatch']['kinds'][$kind->value] ?? $kind->value);

        foreach ($fields as $field) {
            $hint = \sprintf((string) ($lang['keyImport' === $field ? 'kindUnusedKeys' : 'kindUnused'] ?? '%s'), $kindLabel);
            $lang[$field] = [(string) ($lang[$field][0] ?? $field), $hint.' '.(string) ($lang[$field][1] ?? '')];
        }
    }

    private function editedId(DataContainer|null $dc): int
    {
        if ('edit' !== $this->requests->getCurrentRequest()?->query->get('act')) {
            return 0;
        }

        // Not `$dc->id ?? 0`: DataContainer has __get but no __isset (WeightBracketListener).
        return null === $dc ? 0 : (int) $dc->id;
    }

    private function isVariant(int $id): bool
    {
        return (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$id]) > 0;
    }
}
