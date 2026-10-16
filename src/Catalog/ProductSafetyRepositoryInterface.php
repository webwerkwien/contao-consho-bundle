<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * The rows ProductSafetyResolver needs.
 *
 * Deliberately two plain lookups instead of one joined query: the inheritance
 * from a variant to its main product is a RULE, and rules belong in the
 * resolver where they can be read and tested. Expressed as a join it would sit
 * in SQL, where the next person changes it by accident.
 *
 * Both lookups take the page's language and the shop's default language. An
 * empty language, or the default language itself, reads the main row exactly
 * as before — a monolingual shop gets the previous queries.
 */
interface ProductSafetyRepositoryInterface
{
    /**
     * In any other language `safetyWarnings` and `safetyInformation` come from
     * the translation row ONLY, never from the main row (docs/mehrsprachigkeit.md,
     * 4.1) — empty or null when that language has none. The row then also carries
     * `hasOwnSafetyWarnings` and `hasOwnSafetyInformation`: whether THIS article
     * has its own text in the default language, which is what decides whether a
     * variant inherits (the same question VisibilityCriteria asks).
     *
     * @return array<string, mixed>|null pid, physical, manufacturer, safetyWarnings, safetyInformation[, hasOwnSafetyWarnings, hasOwnSafetyInformation]
     */
    public function product(int $id, string $language = '', string $fallback = ''): ?array;

    /**
     * `title` falls back to the default language; nothing else is translatable.
     *
     * @return array<string, mixed>|null title, street, postal, city, state, country, email, contactUrl, euResponsible
     */
    public function manufacturer(int $id, string $language = '', string $fallback = ''): ?array;
}
