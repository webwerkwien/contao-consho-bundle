<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Contao\BackendUser;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Stock\ManualStock;
use Webwerkwien\ContaoConshoBundle\Stock\StockRows;

/**
 * Stock in the product mask (Block G, G-1) — until the page "Lagerstand"
 * (G-7) the only place, and afterwards still the one at the article.
 *
 * ── Which fields where ──────────────────────────────────────────────────
 *
 *  - The settings (stockTracked, stockBackorder, stockWarning) at the MAIN
 *    product of a physical good; the last two only once stock is kept.
 *  - The figures (stockQuantity, stockOpened, stockAvailableFrom) at each
 *    ARTICLE of such a product: a variant, or the main product when it has
 *    none (spec A 3.7).
 *
 * ── The difference, not the figure (G3) ─────────────────────────────────
 *
 * stockQuantity has no column. It shows tl_consho_stock.quantity; the hidden
 * stockOpened carries the same figure as it was when the mask opened. Saving
 * books what the editor CHANGED — entered minus opened — as one atomic
 * booking. Ten on screen, two sold meanwhile, fifteen entered: plus five,
 * thirteen in stock. Writing fifteen would have sold two pieces nobody has.
 * The booking itself is ManualStock's, which a console command calls with its
 * change directly (Connect Stufe 2, CW-2).
 */
final class StockFieldsListener
{
    private const SETTINGS = ['stockTracked', 'stockBackorder', 'stockWarning', 'stockTarget', 'packUnit', 'supplier'];
    private const FIGURES = ['stockQuantity', 'stockOpened', 'stockAvailableFrom', 'supplierSku'];

    public function __construct(
        private readonly Connection $connection,
        private readonly RequestStack $requests,
        private readonly StockRows $rows,
        private readonly ManualStock $stock,
        private readonly Security $security,
    ) {
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onload')]
    public function onLoad(DataContainer|null $dc = null): void
    {
        $palette = (string) ($GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'] ?? '');
        $id = $this->editedId($dc);
        $shown = $id > 0 ? $this->shownFields($id) : [];
        $hidden = array_diff([...self::SETTINGS, ...self::FIGURES], $shown);

        if ([] !== $hidden) {
            $palette = (string) preg_replace('/,(?:'.implode('|', $hidden).')\b/', '', $palette);
        }

        $GLOBALS['TL_DCA']['tl_consho_product']['palettes']['default'] = $palette;
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.stockQuantity.load')]
    public function loadQuantity(mixed $value, DataContainer $dc): string
    {
        $row = $this->rows->activeRow((int) $dc->id);

        return null === $row ? '' : self::display($row['quantity']);
    }

    /**
     * The figure as the mask showed it (G3), under the name Contao gives the
     * field in this form — `stockOpened`, or `stockOpened_<id>` in "Mehrere
     * bearbeiten".
     */
    #[AsCallback(table: 'tl_consho_product', target: 'fields.stockOpened.input_field')]
    public function openedField(DataContainer $dc): string
    {
        $row = $this->rows->activeRow((int) $dc->id);

        return \sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars((string) $dc->inputName, ENT_QUOTES),
            htmlspecialchars(null === $row ? '' : self::display($row['quantity']), ENT_QUOTES),
        );
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.stockQuantity.save')]
    public function saveQuantity(mixed $value, DataContainer $dc): null
    {
        $article = (int) $dc->id;
        $now = time();
        $this->rows->sync($article, $now);
        $row = $this->rows->activeRow($article);

        if (null === $row || !\is_scalar($value) || '' === trim((string) $value)) {
            return null;
        }

        $name = str_replace('stockQuantity', 'stockOpened', (string) $dc->inputName);
        $opened = $this->requests->getCurrentRequest()?->request->get($name);

        try {
            $entered = BigDecimal::of(str_replace(',', '.', trim((string) $value)));
            $before = BigDecimal::of(\is_scalar($opened) && '' !== (string) $opened ? (string) $opened : $row['quantity']);
        } catch (MathException) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_consho_product']['stockInvalid'] ?? 'Please enter a number.');
        }

        $this->stock->adjust($article, $entered->minus($before), $this->userId(), $now);

        return null;
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.stockAvailableFrom.load')]
    public function loadAvailableFrom(mixed $value, DataContainer $dc): int|string
    {
        return $this->rows->activeRow((int) $dc->id)['availableFrom'] ?? '';
    }

    #[AsCallback(table: 'tl_consho_product', target: 'fields.stockAvailableFrom.save')]
    public function saveAvailableFrom(mixed $value, DataContainer $dc): null
    {
        $article = (int) $dc->id;
        $now = time();
        $this->rows->sync($article, $now);
        $row = $this->rows->activeRow($article);

        if (null !== $row) {
            $date = is_numeric($value) && (int) $value > 0 ? (int) $value : null;
            $this->connection->update('tl_consho_stock', ['availableFrom' => $date, 'tstamp' => $now], ['id' => $row['id']]);
        }

        return null;
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.onsubmit')]
    public function onSubmit(DataContainer $dc): void
    {
        $this->rows->sync((int) $dc->id, time());
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.oncopy')]
    public function onCopy(int|string $insertId): void
    {
        // A copy starts at zero: the stock row is not copied with the product.
        $this->rows->sync((int) $insertId, time());
    }

    #[AsCallback(table: 'tl_consho_product', target: 'config.ondelete')]
    public function onDelete(DataContainer $dc): void
    {
        $this->rows->deactivate((int) $dc->id, time());
    }

    /**
     * Called per restored row; a main product comes back with its variants.
     *
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onundo')]
    public function onUndo(string $table, array $row, DataContainer $dc): void
    {
        $this->rows->sync((int) ($row['id'] ?? 0), time());
    }

    /**
     * The id from the parameter: Versions::restore() strips it from $data
     * (ProductRefreshTriggerListener).
     *
     * @param array<string, mixed> $data
     */
    #[AsCallback(table: 'tl_consho_product', target: 'config.onrestore_version')]
    public function onRestore(string $table, int $id, int $version, array $data): void
    {
        $this->rows->sync($id, time());
    }

    /**
     * @return list<string>
     */
    private function shownFields(int $id): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT a.pid, m.stockTracked, t.kind,
                    (SELECT COUNT(*) FROM tl_consho_product v WHERE v.pid = a.id) AS variants
               FROM tl_consho_product a
               JOIN tl_consho_product m ON m.id = CASE WHEN a.pid > 0 THEN a.pid ELSE a.id END
               LEFT JOIN tl_consho_product_type t ON t.id = m.productType
              WHERE a.id = ?',
            [$id],
        );

        if (false === $row) {
            return [];
        }

        $kind = ProductKind::stored(null === $row['kind'] ? null : (string) $row['kind']);
        $isMain = 0 === (int) $row['pid'];

        // A digital good: its stock are the codes of its key pool (G10); the
        // threshold is the one setting it shares (G11), at the main product.
        if (ProductKind::Digital === $kind) {
            return $isMain ? ['stockWarning'] : [];
        }

        if (!$kind->isPhysical()) {
            return [];
        }
        $tracked = (bool) $row['stockTracked'];
        $shown = [];

        if ($isMain) {
            $shown = $tracked ? self::SETTINGS : ['stockTracked'];
        }

        // An article: a variant, or a main product without variants.
        if ($tracked && (!$isMain || 0 === (int) $row['variants'])) {
            $shown = [...$shown, ...self::FIGURES];
        }

        return $shown;
    }

    private function editedId(DataContainer|null $dc): int
    {
        if ('edit' !== $this->requests->getCurrentRequest()?->query->get('act')) {
            return 0;
        }

        // Not `$dc->id ?? 0`: DataContainer has __get but no __isset (WeightBracketListener).
        return null === $dc ? 0 : (int) $dc->id;
    }

    private function userId(): int
    {
        $user = $this->security->getUser();

        return $user instanceof BackendUser ? (int) $user->id : 0;
    }

    private static function display(string $quantity): string
    {
        return (string) BigDecimal::of($quantity)->strippedOfTrailingZeros();
    }
}
