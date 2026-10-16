<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Pdf;

/**
 * HTML in, PDF bytes out (C7).
 *
 * Deliberately this narrow: the invoice template, the snapshot and the
 * question which logo to print are settled before anything reaches here. A
 * shop that wants another engine — Gotenberg, a paid library that tags PDFs —
 * points the alias at its own service and changes nothing else.
 */
interface PdfRendererInterface
{
    /**
     * @param string $html a complete document; every image it needs is inlined
     *                     as a data: URI, because the renderer loads nothing
     */
    public function render(string $html): string;
}
