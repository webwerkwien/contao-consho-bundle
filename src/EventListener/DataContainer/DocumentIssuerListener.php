<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\StringUtil;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\Invoicing;
use Webwerkwien\ContaoConshoBundle\Document\Invoicing\InvoiceProviders;

/**
 * Who issued a document, in the back end's document list (block R, R16,
 * R18): a row written while invoicing was off is the invoice data, not an
 * invoice; a module's document waits for the module or did not reach it.
 * PDF and HTML only where there is one — HTML for Consho's documents alone.
 */
class DocumentIssuerListener
{
    public function __construct(private readonly InvoiceProviders $providers)
    {
    }

    /**
     * @param array<string, mixed> $row
     */
    #[AsCallback(table: 'tl_consho_document', target: 'list.label.label')]
    public function label(array $row, string $label): string
    {
        $note = $this->noteOf($row);

        if (null === $note) {
            return $label;
        }

        $note = '<span class="tl_gray">'.StringUtil::specialchars($note).'</span>';

        // The number comes first in the label; without one the note takes its place.
        return '' === (string) ($row['documentNumber'] ?? '') ? $note.$label : $label.' '.$note;
    }

    #[AsCallback(table: 'tl_consho_document', target: 'list.operations.invoicePdf.button')]
    public function pdfButton(DataContainerOperation $operation): void
    {
        $row = $operation->getRecord();
        $issuer = self::issuerOf($row);

        if (Invoicing::OFF === $issuer || (Invoicing::CONSHO !== $issuer && 'issued' !== (string) ($row['externalState'] ?? ''))) {
            $operation->hide();
        }
    }

    #[AsCallback(table: 'tl_consho_document', target: 'list.operations.invoiceHtml.button')]
    public function htmlButton(DataContainerOperation $operation): void
    {
        if (Invoicing::CONSHO !== self::issuerOf($operation->getRecord())) {
            $operation->hide();
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function noteOf(array $row): string|null
    {
        $issuer = self::issuerOf($row);
        $lang = $GLOBALS['TL_LANG']['tl_consho_document'] ?? [];

        if (Invoicing::CONSHO === $issuer) {
            return null;
        }

        if (Invoicing::OFF === $issuer) {
            return (string) ($lang['notIssued'] ?? 'invoice data, not issued');
        }

        $module = $this->providers->get($issuer)?->label() ?? $issuer;

        return match ((string) ($row['externalState'] ?? '')) {
            'issued' => null,
            'failed' => \sprintf((string) ($lang['handOverFailed'] ?? 'not handed to %s'), $module),
            default => \sprintf((string) ($lang['waitingFor'] ?? 'waiting for %s'), $module),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function issuerOf(array $row): string
    {
        $issuer = (string) ($row['issuer'] ?? '');

        return '' === $issuer ? Invoicing::CONSHO : $issuer;
    }
}
