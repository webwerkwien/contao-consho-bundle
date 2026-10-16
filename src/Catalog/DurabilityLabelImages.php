<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Contao\CoreBundle\Image\Studio\Studio;
use Contao\StringUtil;

/**
 * The GARAN label of an article as an image the page can print (block
 * GARAN, GA3) — product page and checkout.
 *
 * Unresized: the label is a fixed graphic, and its elements must not be
 * "verzerrt, verändert oder voneinander getrennt" (DVO 2025/1960, Anhang II
 * Erläuterung 3). A deleted file drops out without a trace, the way the
 * product images do (ProductDetailController::figures()) — the finding about
 * a missing label does not see a deleted file, so the shop shows nothing
 * rather than a broken image.
 */
class DurabilityLabelImages
{
    public function __construct(
        private readonly DurabilityGuarantees $guarantees,
        private readonly Studio $studio,
    ) {
    }

    /**
     * @param list<int> $articleIds
     *
     * @return array<int, array{years: string, info: string, src: string, width: int|null, height: int|null}> by article id
     */
    public function of(array $articleIds): array
    {
        $images = [];
        $byLabel = [];

        foreach ($this->guarantees->of($articleIds) as $article => $guarantee) {
            $image = $byLabel[$guarantee['label']] ??= $this->image($guarantee['label']);

            if (null !== $image) {
                $images[$article] = ['years' => $guarantee['years'], 'info' => DurabilityGuarantees::INFO_URL, ...$image];
            }
        }

        return $images;
    }

    /**
     * The nested labels for the tiles of a listing (GA8): only where both the
     * nested and the full label can be shown — the nested one links to the
     * full one, which must appear on the first click (Commission guidelines,
     * 3.3.1). An article without a nested label shows nothing on its tile, as
     * before (GA3).
     *
     * @param list<int> $articleIds
     *
     * @return array<int, array{years: string, info: string, src: string, width: int|null, height: int|null, nested: array{src: string, width: int|null, height: int|null}}> by article id
     */
    public function nestedOf(array $articleIds): array
    {
        $images = [];
        $byLabel = [];

        foreach ($this->guarantees->of($articleIds) as $article => $guarantee) {
            if (null === $guarantee['nested']) {
                continue;
            }

            $full = $byLabel[$guarantee['label']] ??= $this->image($guarantee['label']);
            $nested = $byLabel[$guarantee['nested']] ??= $this->image($guarantee['nested']);

            if (null !== $full && null !== $nested) {
                $images[$article] = ['years' => $guarantee['years'], 'info' => DurabilityGuarantees::INFO_URL, ...$full, 'nested' => $nested];
            }
        }

        return $images;
    }

    /**
     * The guarantees FROZEN on an order's lines (GA6) — the order page shows
     * what stood before the button, not today's product.
     *
     * @param list<array<string, mixed>> $items rows of tl_consho_order_item
     *
     * @return list<array{name: string, years: string, info: string, src: string, width: int|null, height: int|null}>
     */
    public function ofOrderItems(array $items): array
    {
        $images = [];
        $byLabel = [];

        foreach ($items as $item) {
            $label = $item['durabilityLabel'] ?? null;
            $years = GuaranteeYears::of($item['durabilityYears'] ?? null);

            if ('product' !== (string) ($item['lineType'] ?? '') || !GuaranteeYears::qualifies($years) || !\is_string($label) || 16 !== \strlen($label)) {
                continue;
            }

            $uuid = StringUtil::binToUuid($label);
            $image = $byLabel[$uuid] ??= $this->image($uuid);

            if (null !== $image) {
                $images[] = ['name' => html_entity_decode((string) ($item['productName'] ?? ''), \ENT_QUOTES | \ENT_HTML5, 'UTF-8'), 'years' => $years, 'info' => DurabilityGuarantees::INFO_URL, ...$image];
            }
        }

        return $images;
    }

    /**
     * @return array{src: string, width: int|null, height: int|null}|null
     */
    private function image(string $uuid): array|null
    {
        $figure = $this->studio->createFigureBuilder()->fromUuid($uuid)->buildIfResourceExists();

        if (null === $figure) {
            return null;
        }

        $img = $figure->getImage()->getImg();

        return [
            'src' => (string) ($img['src'] ?? ''),
            'width' => isset($img['width']) ? (int) $img['width'] : null,
            'height' => isset($img['height']) ? (int) $img['height'] : null,
        ];
    }
}
