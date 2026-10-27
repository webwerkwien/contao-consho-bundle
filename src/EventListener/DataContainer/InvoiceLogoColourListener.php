<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\StringUtil;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Webwerkwien\ContaoConshoBundle\Document\InvoiceLogo;

/**
 * Refuses a CMYK logo when the shop is saved (C-8).
 *
 * dompdf cannot put a CMYK image into a PDF/A invoice — it throws. Caught
 * here, the operator reads why at the field and can save the logo as RGB;
 * caught only at rendering (InvoiceLogo), the invoices would go out without
 * a logo and the operator would learn it from a warning later.
 *
 * A save callback, so the exception is shown at the field and keeps the form.
 */
class InvoiceLogoColourListener
{
    public function __construct(
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
    ) {
    }

    #[AsCallback(table: 'tl_consho_shop', target: 'fields.invoiceLogo.save')]
    public function __invoke(mixed $value): mixed
    {
        if (!\is_string($value) || 16 !== \strlen($value)) {
            return $value;
        }

        try {
            $contents = $this->files->read(Uuid::fromString(StringUtil::binToUuid($value)));
        } catch (UnableToResolveUuidException|VirtualFilesystemException) {
            // Not ours to judge: FileTree has already checked that the file
            // exists and has an allowed extension.
            return $value;
        }

        if (InvoiceLogo::isCmyk($contents)) {
            throw new \RuntimeException($GLOBALS['TL_LANG']['tl_consho_shop']['invoiceLogoCmyk'] ?? 'CMYK');
        }

        return $value;
    }
}
