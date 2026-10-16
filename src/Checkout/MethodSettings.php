<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Checkout;

use Contao\StringUtil;

/**
 * Reading the two stored forms both method repositories share: the selection
 * of a shop (checkboxWizard blob) and the module settings (jsonData).
 */
final class MethodSettings
{
    /**
     * Ids in the order the operator dragged them; the first is the default (C2).
     *
     * @return list<int>
     */
    public static function selectedIds(mixed $blob): array
    {
        $ids = [];

        foreach (StringUtil::deserialize($blob, true) as $value) {
            $id = is_numeric($value) ? (int) $value : 0;

            if ($id > 0 && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The decoded virtual fields, or [] for an empty or broken column — a
     * damaged setting makes a method unconfigured, not the checkout crash.
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $json): array
    {
        if (!\is_string($json) || '' === $json) {
            return [];
        }

        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        // A JSON list ('["a","b"]') decodes to a valid PHP array too, but it
        // carries no field names — not a broken config, just not this one.
        if (!\is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        return $decoded;
    }
}
