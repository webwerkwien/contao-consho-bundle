<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Widget;

use Contao\StringUtil;
use Contao\TextField;

/**
 * A text field that suggests the values already in use (Block G, G-7, E5 a) —
 * the browser's own <datalist>, no script.
 *
 * Michael, 2026-10-05: "ich will aber vermeiden, dass ein und der selbe
 * Lieferant mehrere Schreibweisen bekommt … Dropdown wo man auch neuen
 * Lieferanten anlegen kann (ohne DB)". Contao has no select that creates
 * entries; this is the nearest: typing offers the names in use, a new one is
 * simply typed. The suggestions come from the field's options_callback;
 * SupplierListener matches spelling variants on save.
 *
 * Registered as inputType `conshoSuggest` (config.php, BE_FFL).
 */
class SuggestField extends TextField
{
    public function generate(): string
    {
        $listId = 'ctrl_'.$this->strId.'_suggestions';
        $html = (string) preg_replace('/<input /', '<input list="'.$listId.'" autocomplete="off" ', parent::generate(), 1);
        $options = '';

        foreach ($this->arrConfiguration['options'] ?? [] as $option) {
            $value = \is_array($option) ? (string) ($option['value'] ?? '') : (string) $option;

            if ('' !== $value) {
                $options .= \sprintf('<option value="%s">', StringUtil::specialchars($value));
            }
        }

        return $html.\sprintf('<datalist id="%s">%s</datalist>', $listId, $options);
    }
}
