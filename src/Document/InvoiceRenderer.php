<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Document\EInvoice\ZugferdInvoice;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\PdfRendererInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * An invoice as HTML or PDF — both from the same snapshot, the same view and
 * the same template (8.2, 4.3), in the language of the order. The delivery
 * note (dev#2) goes through here as well, with its own view and template, so
 * archive, download and handover need no second path.
 *
 * `logoMissing` tells the caller that the snapshot named a logo that could not
 * be printed (InvoiceLogo). The document itself does not say so: it is the
 * operator's warning, not the customer's business.
 */
class InvoiceRenderer
{
    /**
     * WITH the folder — Contao 5.7 keeps it in the name (debug:contao-twig on
     * c5, 2026-09-24). Without it the first rendering on c5 was a 500.
     */
    public const TEMPLATE = '@Contao/document/consho_invoice.html.twig';

    // dev#2: the delivery note has a page of its own — no amounts, no tax.
    public const DELIVERY_NOTE_TEMPLATE = '@Contao/document/consho_delivery_note.html.twig';

    public function __construct(
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly InvoiceView $view,
        private readonly InvoiceLogo $logo,
        private readonly PdfRendererInterface $pdf,
        // Block R (R9): the ZUGFeRD XML in the PDF. Optional for the tests
        // built by hand; the container always wires it.
        private readonly ZugferdInvoice|null $eInvoice = null,
        // dev#2; optional likewise.
        private readonly DeliveryNoteView|null $deliveryNoteView = null,
    ) {
    }

    /**
     * @param array<string, mixed> $document the tl_consho_document row, payload included
     * @param bool                 $regenerated whether an archived original should have been delivered instead
     *
     * @return array{html: string, logoMissing: bool}
     */
    public function html(array $document, bool $regenerated = false): array
    {
        return $this->render($document, $regenerated, false);
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array{pdf: string, logoMissing: bool}
     */
    public function pdf(array $document, bool $regenerated = false): array
    {
        // Paged: the payment block and the footer go to the foot of the page,
        // which only a page has. The HTML version keeps them in the flow.
        $rendered = $this->render($document, $regenerated, true);
        $pdf = $this->pdf->render($rendered['html']);

        // Every PDF of a document — archived, mailed, downloaded — comes from
        // here, so the XML is in each of them or in none.
        if (null !== $this->eInvoice) {
            $pdf = $this->eInvoice->embed($pdf, $document, $this->payloadOf($document));
        }

        return ['pdf' => $pdf, 'logoMissing' => $rendered['logoMissing']];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array{html: string, logoMissing: bool}
     */
    private function render(array $document, bool $regenerated, bool $paged): array
    {
        $payload = $this->payloadOf($document);
        $logo = $this->logo->resolve(\is_array($payload['logo'] ?? null) ? $payload['logo'] : null);

        if (DocumentType::DeliveryNote->value === ($document['type'] ?? '')) {
            $template = self::DELIVERY_NOTE_TEMPLATE;
            $view = ($this->deliveryNoteView ?? new DeliveryNoteView(new MoneyFormatter()))->build($document, $payload);
            $name = 'note';
        } else {
            $template = self::TEMPLATE;
            $view = $this->view->build($document, $payload);
            $name = 'invoice';
        }

        $previous = $this->switchLocale((string) $view['language']);

        try {
            $html = $this->twig->render($template, [
                $name => $view,
                'logo' => $logo['src'],
                'regenerated' => $regenerated,
                'paged' => $paged,
            ]);
        } finally {
            // Restored even when rendering threw — see MailRenderer.
            if (null !== $previous) {
                $this->switchLocale($previous);
            }
        }

        return ['html' => $html, 'logoMissing' => $logo['missing']];
    }

    /**
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private function payloadOf(array $document): array
    {
        $payload = $document['payload'] ?? null;

        if (\is_array($payload)) {
            return $payload;
        }

        /*
         * A snapshot that does not decode is not rendered as an empty invoice.
         * An invoice without lines and without totals would look like a
         * document; this is a broken record, and the caller should say so.
         */
        $decoded = \is_string($payload) ? json_decode($payload, true) : null;

        if (!\is_array($decoded)) {
            throw new \UnexpectedValueException(\sprintf('The snapshot of document %s cannot be read.', (string) ($document['id'] ?? '?')));
        }

        return $decoded;
    }

    private function switchLocale(string $locale): string|null
    {
        // Checked at run time, not as an intersection type — see MailRenderer.
        if (!$this->translator instanceof LocaleAwareInterface) {
            return null;
        }

        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale);

        return $previous;
    }
}
