<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Only file types that are both suitable for the invoice AND allowed by this
 * installation can be chosen as the invoice logo (Michael, 2026-09-24).
 *
 * Suitable: what the DCA lists — PNG and JPEG, which the PDF engine renders
 * as the file manager shows them. Allowed: `contao.image.valid_extensions`,
 * the image types the installation handles at all. The field offers the
 * intersection.
 *
 * FileTree enforces `extensions` on save (checkValue()), not only in the
 * picker, so this is a limit and not a hint.
 *
 * ⚠️ An EMPTY intersection must not become `extensions => ''`: FileTree
 * checks only `if ($this->extensions …)`, so an empty string would switch the
 * check OFF and allow every file. The field leaves the palette instead.
 */
final class InvoiceLogoFieldListener
{
    /**
     * @param list<string> $validExtensions
     */
    public function __construct(
        #[Autowire('%contao.image.valid_extensions%')]
        private readonly array $validExtensions,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'config.onload')]
    public function __invoke(): void
    {
        $field = &$GLOBALS['TL_DCA']['tl_consho_shop']['fields']['invoiceLogo'];
        $suitable = array_map('strtolower', array_map('trim', explode(',', (string) ($field['eval']['extensions'] ?? ''))));
        $allowed = array_map('strtolower', $this->validExtensions);

        $extensions = array_values(array_intersect($suitable, $allowed));

        if ([] !== $extensions) {
            $field['eval']['extensions'] = implode(',', $extensions);

            return;
        }

        foreach ($GLOBALS['TL_DCA']['tl_consho_shop']['palettes'] as $key => $palette) {
            if (\is_string($palette) && '__selector__' !== $key) {
                $GLOBALS['TL_DCA']['tl_consho_shop']['palettes'][$key] = preg_replace('/,invoiceLogo(?=[,;]|$)/', '', $palette);
            }
        }
    }
}
