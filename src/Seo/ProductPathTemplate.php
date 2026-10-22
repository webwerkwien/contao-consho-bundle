<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Seo;

/**
 * The address pattern of the product detail page, as the operator typed it.
 *
 * ── Why the operator may type this at all ─────────────────────────────────
 *
 * Contao's own "route path" field is a display, not an input: it has no `sql`
 * entry and is rendered by an input_field_callback showing the computed path
 * (core, contao/dca/tl_page.php). A configurable pattern therefore has to come
 * from the page type itself, through DynamicRouteInterface — which is exactly
 * what it is for.
 *
 * ── Why only these four placeholders ──────────────────────────────────────
 *
 * A placeholder is admissible when it follows from the product ALONE. Anything
 * else produces more than one address for one product, and then somebody has
 * to nominate the real one.
 *
 * The category is the placeholder people will ask for, because every other
 * system offers it. Shopware, WooCommerce, Magento and PrestaShop all had to
 * invent a "main category" once they did — see docs/block-b-katalog-frontend.md,
 * section 4.2. A product is honestly in several categories, and which one is
 * "the real" one is a question without an answer.
 *
 * Names are English like every other identifier in this bundle (block A,
 * section 3.0); the German explanation lives in the field help.
 *
 * ── The template is RELATIVE to its page ──────────────────────────────────
 *
 * It describes what comes after the page, not the whole address. That is not a
 * simplification but what Contao supports — PageRoute::__construct:
 *
 *     } elseif (!str_starts_with($path, '/')) {
 *         $path = '/'.($pageModel->alias ?: $pageModel->id).'/'.$path;
 *     }
 *
 * An absolute template would need a per-type `pathRegex`, and that is compiled
 * once for the whole page type (RegisterPagesPass) — it cannot differ from page
 * to page, which is exactly what a configurable template has to do. Keeping the
 * page alias as the prefix also keeps the page in the tree, with its layout,
 * its protection and its language.
 */
final class ProductPathTemplate
{
    public const ALIAS = 'alias';
    public const SKU = 'sku';
    public const MANUFACTURER = 'manufacturer';
    public const TYPE = 'type';

    /** @var list<string> */
    public const KNOWN = [self::ALIAS, self::SKU, self::MANUFACTURER, self::TYPE];

    /**
     * Must be present, or the template does not address a product.
     *
     * Only the alias: it is unique in the mask. {sku} used to count as well,
     * but the article number is neither unique nor mandatory (block B: "die SKU
     * ist nicht eindeutig") — with a template of only {sku}, two main products
     * sharing a number got one address and ProductAddressLookup found just one
     * of them, and a product without a number had none at all. {sku} stays
     * allowed next to {alias} (Michael, 2026-10-22: "Variante 1").
     */
    private const IDENTIFYING = self::ALIAS;

    /**
     * @param list<string> $placeholders in the order they appear
     */
    private function __construct(
        private readonly string $pattern,
        private readonly array $placeholders,
    ) {
    }

    /**
     * @throws InvalidPathTemplate
     */
    public static function fromString(string $pattern): self
    {
        $pattern = trim(trim($pattern), '/');

        if ('' === $pattern) {
            throw InvalidPathTemplate::isEmpty();
        }

        preg_match_all('/\{([a-zA-Z_]+)\}/', $pattern, $matches);

        $found = $matches[1];

        foreach ($found as $name) {
            if (!\in_array($name, self::KNOWN, true)) {
                throw InvalidPathTemplate::unknownPlaceholder($name, self::KNOWN);
            }
        }

        foreach (array_count_values($found) as $name => $count) {
            if ($count > 1) {
                throw InvalidPathTemplate::repeatedPlaceholder((string) $name);
            }
        }

        if (!\in_array(self::IDENTIFYING, $found, true)) {
            throw InvalidPathTemplate::cannotIdentifyAProduct();
        }

        return new self($pattern, $found);
    }

    public function toString(): string
    {
        return $this->pattern;
    }

    /**
     * @return list<string>
     */
    public function placeholders(): array
    {
        return $this->placeholders;
    }

    /**
     * @param array<string, string> $values
     */
    public function render(array $values): string
    {
        $path = $this->pattern;

        foreach ($this->placeholders as $name) {
            $path = str_replace('{'.$name.'}', $values[$name] ?? '', $path);
        }

        /*
         * A product without a manufacturer still needs an address, so the
         * segment is dropped rather than filled with something else. It stays
         * unique because the alias is always part of the pattern.
         */
        $path = (string) preg_replace('#/+#', '/', $path);

        return trim($path, '/');
    }
}
