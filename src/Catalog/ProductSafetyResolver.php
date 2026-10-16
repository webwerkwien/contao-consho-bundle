<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

use Symfony\Component\Intl\Countries;

/**
 * Assembles what the product page shows under the GPSR.
 *
 * Three rules live here rather than in a query or a template:
 *
 *  - A variant with nothing of its own inherits from its main product. Copying
 *    the manufacturer onto every variant would go stale one variant at a time.
 *  - A manufacturer inside the Union has no responsible person to show, even
 *    when one is recorded. A heading over an address that means nothing here is
 *    worse than no heading.
 *  - A missing responsible person leaves EMPTY fields, never half a block. The
 *    gap belongs on the back end start page, where somebody can fix it — not on
 *    the product page, where it only confuses the buyer.
 */
final class ProductSafetyResolver
{
    public function __construct(private readonly ProductSafetyRepositoryInterface $repository)
    {
    }

    /**
     * In the page's language, without falling back for the safety texts.
     *
     * $language and $fallback as everywhere else: the start point's language
     * and ShopLanguages::fallbackOf(). Leaving them empty reads the default
     * language, as before.
     */
    public function forProduct(int $productId, string $language = '', string $fallback = ''): ProductSafetyInfo
    {
        $product = $this->repository->product($productId, $language, $fallback);

        if (null === $product) {
            return new ProductSafetyInfo();
        }

        $parent = ((int) ($product['pid'] ?? 0)) > 0
            ? $this->repository->product((int) $product['pid'], $language, $fallback)
            : null;

        $required = (bool) ($product['physical'] ?? false);

        $manufacturerId = (int) ($product['manufacturer'] ?? 0)
            ?: (int) ($parent['manufacturer'] ?? 0);

        $manufacturer = $manufacturerId > 0 ? $this->repository->manufacturer($manufacturerId, $language, $fallback) : null;
        $euResponsible = $this->euResponsibleOf($manufacturer, $language, $fallback);

        return new ProductSafetyInfo(
            required: $required,
            manufacturerName: $this->text($manufacturer['title'] ?? null),
            manufacturerAddress: $this->address($manufacturer),
            manufacturerEmail: $this->text($manufacturer['email'] ?? null),
            manufacturerContactUrl: $this->text($manufacturer['contactUrl'] ?? null),
            euResponsibleName: $this->text($euResponsible['title'] ?? null),
            euResponsibleAddress: $this->address($euResponsible),
            euResponsibleEmail: $this->text($euResponsible['email'] ?? null),
            warnings: $this->inherited($product, $parent, 'safetyWarnings'),
            safetyInformation: $this->inherited($product, $parent, 'safetyInformation'),
        );
    }

    /**
     * @param array<string, mixed>|null $manufacturer
     *
     * @return array<string, mixed>|null
     */
    private function euResponsibleOf(array|null $manufacturer, string $language, string $fallback): ?array
    {
        if (null === $manufacturer) {
            return null;
        }

        // Inside the Union the manufacturer answers for itself. A recorded
        // responsible person is then simply not part of the offer.
        if (EuMemberStates::contains((string) ($manufacturer['country'] ?? ''))) {
            return null;
        }

        $id = (int) ($manufacturer['euResponsible'] ?? 0);

        return $id > 0 ? $this->repository->manufacturer($id, $language, $fallback) : null;
    }

    /**
     * The variant's own text, otherwise its main product's — in the language
     * the rows were read in.
     *
     * WHETHER the variant has a text of its own is decided in the default
     * language, in every language (`hasOwn…`, see the repository interface).
     * VisibilityCriteria::variant() asks the same question: a variant with its
     * own German warning and no French one is hidden in French. Deciding it on
     * the French row instead would put the main product's French warning next
     * to a variant it does not describe. Without the flag — the default
     * language — the text itself answers, as it always did.
     *
     * @param array<string, mixed>      $product
     * @param array<string, mixed>|null $parent
     */
    private function inherited(array $product, array|null $parent, string $field): string
    {
        $own = $this->text($product[$field] ?? null);
        $flag = 'hasOwn'.ucfirst($field);
        $hasOwn = \array_key_exists($flag, $product) ? (bool) $product[$flag] : '' !== $own;

        return $hasOwn ? $own : $this->text($parent[$field] ?? null);
    }

    /**
     * Multi-line and human, not machine-readable.
     *
     * This goes into a page for a buyer to read, and the country stands there
     * translated rather than as an ISO code. Block B may wrap the lines; it must
     * not have to parse them.
     *
     * @param array<string, mixed>|null $row
     */
    private function address(array|null $row): string
    {
        if (null === $row) {
            return '';
        }

        $code = strtoupper(trim((string) ($row['country'] ?? '')));
        $locale = \is_string($GLOBALS['TL_LANGUAGE'] ?? null) ? $GLOBALS['TL_LANGUAGE'] : 'en';

        $lines = [
            $this->text($row['street'] ?? null),
            trim($this->text($row['postal'] ?? null).' '.$this->text($row['city'] ?? null)),
            $this->text($row['state'] ?? null),
            '' !== $code && Countries::exists($code) ? Countries::getName($code, $locale) : $code,
        ];

        return implode("\n", array_filter($lines, static fn (string $line): bool => '' !== $line));
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }
}
