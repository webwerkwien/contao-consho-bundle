<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Catalog;

/**
 * Encodes and decodes custom field values.
 *
 * Custom fields are deliberately NOT filterable, which is why they live in one
 * JSON column instead of a value table — see docs/block-a-fundament.md,
 * section 3.1. The column is `text` rather than a native `json` type: whether
 * Contao's schema generator migrates a json column cleanly is unverified, and
 * a column type that fails during contao:migrate blocks the whole rollout.
 * This class encapsulates the encoding either way, so switching later is a
 * migration of one column, not a change to the code.
 *
 * Decoding never throws: a broken column must not take a product page down.
 */
final class CustomFieldCodec
{
    /**
     * @param array<string, mixed> $values
     */
    public function encode(array $values): string
    {
        return json_encode(
            $values,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        // Valid JSON is not necessarily an object. A scalar, a list or null
        // would silently pass as a value set otherwise.
        return \is_array($decoded) ? $decoded : [];
    }
}
