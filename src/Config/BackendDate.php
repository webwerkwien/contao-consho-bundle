<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Config;

use Contao\Date;

/**
 * A day in the back end, in the date format of the installation (Contao's
 * settings, `dateFormat`) — the way Contao's own lists show one. Michael,
 * 2026-09-30: „in Contao definiert man, wie ein Datum angezeigt werden soll
 * unter Einstellungen". Before, findings and the withdrawal list wrote
 * `d.m.Y` into every language.
 *
 * Falls back to `d.m.Y` where the settings are not loaded (a test). Meant for
 * the back end: a format with month or day names loads Contao's language
 * file (Date::parse), which needs the framework.
 */
final class BackendDate
{
    public static function format(int $timestamp): string
    {
        return Date::parse(self::setting('dateFormat', 'd.m.Y'), $timestamp);
    }

    /**
     * Date and time, `datimFormat`.
     */
    public static function withTime(int $timestamp): string
    {
        return Date::parse(self::setting('datimFormat', 'd.m.Y H:i'), $timestamp);
    }

    private static function setting(string $key, string $fallback): string
    {
        $format = $GLOBALS['TL_CONFIG'][$key] ?? null;

        return \is_string($format) && '' !== $format ? $format : $fallback;
    }
}
