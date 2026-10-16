<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\GuaranteeYears;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Catalog\PublicFiles;
use Webwerkwien\ContaoConshoBundle\Shipping\ArticleShippingDataInterface;

/**
 * The manufacturer's durability guarantee and its GARAN label (block GARAN,
 * § 4 Abs. 1 Z 12a FAGG) — where they are asked and what is refused.
 *
 * ── Shown ────────────────────────────────────────────────────────────────
 *
 * At the main product of physical goods only. The FAGG speaks of "Ware"; a
 * file, a service or a voucher has no durability (GA5). A variant inherits
 * the main product's guarantee (GA2) — shown there, the fields would do
 * nothing. Filled ones stay visible wherever they are, so that a value set
 * before the type changed can still be emptied (the rule of
 * SaleModeListener, acceptance DL find 1).
 *
 * ── Refused ──────────────────────────────────────────────────────────────
 *
 * - 1 or 2 years: the label exists only for "mehr als zwei Jahre"; a shorter
 *   guarantee is no case of Z 12a, and a label for it would claim one.
 * - Anything but whole or half years (GA9, GuaranteeYears).
 * - Either field on anything but physical goods.
 * - Either field on a variant (review GARAN, 2): DurabilityGuarantees reads
 *   the main product only, so a value there would do nothing while looking
 *   as if it did — "Mehrere bearbeiten" shows the fields at variants too.
 * - A label in a folder the web cannot reach (acceptance on c5): the shop
 *   would print a broken image (PublicFiles).
 *
 * Emptying is always allowed, wherever a value stands.
 *
 * The kind comes from the STORED product type (ArticleShippingDataInterface).
 * Changing the type and these fields in one submit checks against the old
 * one — DC_Table writes the whole record at the end (review GARAN, 3).
 * ProductKindMismatchIssueSource names a guarantee left on a type changed
 * that way, and the next save refuses it.
 */
final class DurabilityGuaranteeListener
{
    // The nested label (GA8) follows the full one in every rule.
    private const FIELDS = ['durabilityYears', 'durabilityLabel', 'durabilityNested'];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly ArticleShippingDataInterface $articles,
        // Optional for the tests that do not ask about the folder.
        private readonly PublicFiles|null $files = null,
    ) {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        if ('edit' !== $this->requests->getCurrentRequest()?->query->get('act') || null === $dc || (int) $dc->id < 1) {
            return;
        }

        $id = (int) $dc->id;
        $row = $this->connection->fetchAssociative('SELECT pid, durabilityYears, durabilityLabel, durabilityNested FROM tl_consho_product WHERE id = ?', [$id]);

        if (false === $row || ((int) $row['pid'] < 1 && $this->isGoods($id))) {
            return;
        }

        $hidden = array_values(array_filter(self::FIELDS, static fn (string $field): bool => self::isEmpty($row[$field])));

        if ([] === $hidden) {
            return;
        }

        $palette = (string) ($GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'] ?? '');
        $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'] = (string) preg_replace('/,(?:'.implode('|', $hidden).')\b/', '', $palette);
    }

    /**
     * "5" and "2.5", not MySQL's "5.0" (GA9) — in exactly the form saveYears()
     * returns. DC_Table writes and versions a field whose saved value differs
     * strictly from the loaded one; "2,5" here would make every save of the
     * product a change nobody made (review GARAN part 2, 1). The mask takes a
     * comma all the same.
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.durabilityYears.load')]
    public function loadYears(mixed $value): string
    {
        return GuaranteeYears::of($value);
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.durabilityYears.save')]
    public function saveYears(mixed $value, DataContainer $dc): string
    {
        $years = GuaranteeYears::parse($value);

        // Whole or half years only (GA9) — "4,2" would be a label nobody may print.
        if (null === $years) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_product']['durabilityYearsInvalid'] ?? 'Enter the duration in whole or half years, e.g. 3 or 2,5.'));
        }

        if (GuaranteeYears::isNone($years)) {
            return GuaranteeYears::NONE;
        }

        if (!GuaranteeYears::qualifies($years)) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_product']['durabilityYearsTooShort'] ?? 'A durability guarantee carries the GARAN label only if it runs longer than two years.'));
        }

        $this->refuseUnlessMainGoods((int) $dc->id);

        return $years;
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.durabilityLabel.save')]
    #[AsCallback(table: 'tl_consho_product', target: 'fields.durabilityNested.save')]
    public function saveLabel(mixed $value, DataContainer $dc): mixed
    {
        if (self::isEmpty($value)) {
            return $value;
        }

        $this->refuseUnlessMainGoods((int) $dc->id);

        if (null !== $this->files && \is_string($value) && !$this->files->isPublic($value)) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_product']['durabilityLabelNotPublic'] ?? 'The folder of the GARAN label is not public.'));
        }

        return $value;
    }

    private function refuseUnlessMainGoods(int $id): void
    {
        if ((int) $this->connection->fetchOne('SELECT pid FROM tl_consho_product WHERE id = ?', [$id]) > 0) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_product']['durabilityMainOnly'] ?? 'The durability guarantee is set at the main product; its variants inherit it.'));
        }

        if (!$this->isGoods($id)) {
            throw new \RuntimeException((string) ($GLOBALS['TL_LANG']['tl_consho_product']['durabilityNotGoods'] ?? 'The GARAN label applies to goods only.'));
        }
    }

    private function isGoods(int $id): bool
    {
        return ProductKind::stored($this->articles->of([$id])[$id]['kind'] ?? null)->isPhysical();
    }

    private static function isEmpty(mixed $value): bool
    {
        // "0.0" is how MySQL returns an empty duration (decimal, GA9); the
        // length guard keeps a 16-byte label from ever being read as a number.
        return null === $value || '' === $value || 0 === $value
            || (\is_string($value) && \strlen($value) < 16 && is_numeric($value) && 0.0 === (float) $value);
    }
}
