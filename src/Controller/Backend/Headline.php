<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\Backend;

use Contao\StringUtil;

/**
 * The headline of a page behind an operation (`key=…`) names the order, not
 * the record's ID.
 *
 * Contao builds that headline itself, AFTER the page's callback ran
 * (Backend::getBackendModule()): the operation's title, `TL_LANG[table][key][1]`,
 * formatted with the ID from the URL — "der Bestellung ID 7". That is what
 * Contao shows when it knows nothing better. The callback knows the order
 * number, so it rewrites the title for this one request: "ID %s" becomes the
 * subject, and the rest stays as the language file has it.
 *
 * Contao runs sprintf() over the result and prints it as HTML: a `%` in the
 * subject is doubled, the subject escaped.
 */
final class Headline
{
    public static function name(string $table, string $key, string $subject): void
    {
        $title = $GLOBALS['TL_LANG'][$table][$key][1] ?? null;

        if (!\is_string($title) || !str_contains($title, 'ID %s') || '' === trim($subject)) {
            return;
        }

        $GLOBALS['TL_LANG'][$table][$key][1] = str_replace('%', '%%', str_replace('ID %s', StringUtil::specialchars($subject), $title));
    }
}
