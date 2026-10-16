<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\StringUtil;
use Symfony\Component\Validator\Constraints\Iban;
use Symfony\Component\Validator\Validation;

/**
 * Checks the IBAN of a prepayment method on save (spec 5.1).
 *
 * The check digit is the point: a transposed digit on the invoice sends the
 * customer's money to someone else's account, and nobody notices until the
 * operator wonders why nothing arrived. Stored compact and upper-case, the
 * form every bank accepts.
 *
 * The validator is built locally rather than injected: it needs no metadata,
 * translator or cache.
 *
 * ⚠️ This save callback is why `iban` carries `'targetColumn' => 'jsonData'`
 * in the DCA: Contao maps a field with a save_callback to no column by itself.
 */
class IbanListener
{
    #[AsCallback(table: 'tl_consho_payment_method', target: 'fields.iban.save')]
    public function __invoke(mixed $value): string
    {
        // The `u` modifier makes `\s` Unicode-aware, so it also matches a
        // non-breaking space (U+00A0) or narrow no-break space (U+202F), both
        // pasted from online IBAN generators; `\p{Z}` covers the rest of the
        // Unicode space separators. `\p{Cf}` additionally strips invisible
        // format characters such as a soft hyphen (U+00AD), a zero-width
        // space (U+200B) or a BOM (U+FEFF) left over from a copy out of a
        // PDF. Symfony's IbanValidator strips whitespace itself and would
        // report the IBAN valid, so it would be stored with an invisible
        // character inside.
        $compact = preg_replace('/[\s\p{Z}\p{Cf}]+/u', '', (string) $value);

        if (null === $compact) {
            // preg_replace() returns null, not '', when the subject is not
            // valid UTF-8 — that is not an empty IBAN, it is a broken one.
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['ibanInvalid'], StringUtil::specialchars((string) $value)));
        }

        $iban = strtoupper($compact);

        if ('' === $iban) {
            return '';
        }

        if (\count(Validation::createValidator()->validate($iban, new Iban())) > 0) {
            // Widget::getErrorAsHTML() prints the message unescaped, so an
            // IBAN typed with markup must be escaped here, not there.
            throw new \RuntimeException(\sprintf($GLOBALS['TL_LANG']['CONSHO']['errors']['ibanInvalid'], StringUtil::specialchars($iban)));
        }

        return $iban;
    }
}
