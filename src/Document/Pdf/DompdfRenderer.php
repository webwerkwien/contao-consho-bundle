<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Pdf;

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The PDF engine of the core, dompdf behind PdfRendererInterface (C7, 8.2).
 *
 * ── Nothing is loaded, from anywhere ─────────────────────────────────────
 *
 * The only allowed protocol is `data://`. A remote URL in an invoice would
 * make every rendering a request to someone else's server, carrying who is
 * looking at which invoice when; a `file://` URL would let whatever reaches
 * the template read the project's files. The logo — the one image an invoice
 * has — is checked against its snapshot hash by the caller and handed over as
 * a data: URI (InvoiceLogo), so the engine never needs a path.
 *
 * PHP and JavaScript inside the document are off as well. dompdf turns both
 * off by default for PHP and on for JavaScript; neither belongs in an invoice.
 *
 * ── PDF/A ────────────────────────────────────────────────────────────────
 *
 * dompdf's PDF/A mode refuses any font it cannot embed entirely — the
 * fourteen PDF core fonts among them (Cpdf.php: "A fully embeddable font must
 * be used when generating a document in PDF/A mode"). DejaVu Sans ships with
 * dompdf and is embeddable, so it is the default font here, and a template
 * that names Helvetica gets it instead of an exception.
 *
 * ✅ Validated on 2026-09-24 with veraPDF 1.30.2 (demo.verapdf.org, a sample
 * invoice with fictitious data): **PDF/A-3b compliant**, 146 rules passed,
 * none failed. **PDF/UA-1 fails**, as expected — dompdf writes no structure
 * tree, no marked content and no document language, so the PDF is not
 * accessible. That is what the HTML version of the invoice is for (4.3).
 * Revalidate after changing the template or upgrading dompdf.
 *
 * ── Font cache under var/ ────────────────────────────────────────────────
 *
 * dompdf writes font metrics next to the fonts by default, which is inside
 * vendor/ and read-only on many hosts. var/ is where Symfony keeps what it
 * may rebuild.
 */
final class DompdfRenderer implements PdfRendererInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/dompdf')]
        private readonly string $cacheDir,
    ) {
    }

    public function render(string $html): string
    {
        if (!is_dir($this->cacheDir) && !@mkdir($this->cacheDir, 0o775, true) && !is_dir($this->cacheDir)) {
            throw new \RuntimeException(\sprintf('The PDF font cache "%s" cannot be created.', $this->cacheDir));
        }

        $options = new Options();
        $options->setIsRemoteEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setAllowedProtocols(['data://']);
        $options->setChroot([$this->cacheDir]);
        $options->setFontCache($this->cacheDir);
        $options->setFontDir($this->cacheDir);
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsPdfAEnabled(true);

        // Before the constructor: that is when dompdf reads the map.
        $this->ensureFontMap($options->getRootDir().'/lib/fonts/');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * 🔴 The default font is not enough (found by DompdfRendererTest).
     *
     * dompdf maps the generic CSS families to the PDF core fonts —
     * `sans-serif` to Helvetica, `serif` to Times, `monospace` to Courier —
     * and PDF/A refuses every one of them with an exception. A template that
     * writes `font-family: sans-serif`, which is how any stylesheet ends, made
     * the whole invoice fail. Every core family therefore points at its
     * DejaVu counterpart, which ships with dompdf and embeds.
     *
     * Symbol and ZapfDingbats have no counterpart and go to DejaVu Sans,
     * which carries most of their characters; a missing glyph beats an
     * invoice that does not render.
     *
     * The map lives in installed-fonts.json in the font directory, which is
     * why the font directory is the cache directory and not dompdf's own in
     * vendor/. dompdf reads it once, when Dompdf is constructed
     * (FontMetrics::loadFontFamilies()).
     *
     * 🔴 Written ONCE and atomically, not per rendering (Fable review
     * 2026-09-24). The first version called setFontFamily() ten times per
     * PDF, and each call rewrites the file with a plain file_put_contents.
     * Two invoices rendered at the same moment — two purchases, both archiving
     * — and one process could read a half-written file, get `null` as its
     * font map, fall back to Helvetica and die in PDF/A mode. Now the file is
     * compared first and, only when it differs (first run, or vendor/ moved
     * on a deploy), written to a temporary name and renamed into place: a
     * reader sees the old file or the new one, never half of either.
     */
    private function ensureFontMap(string $fonts): void
    {
        $sans = ['normal' => 'DejaVuSans', 'bold' => 'DejaVuSans-Bold', 'italic' => 'DejaVuSans-Oblique', 'bold_italic' => 'DejaVuSans-BoldOblique'];
        $serif = ['normal' => 'DejaVuSerif', 'bold' => 'DejaVuSerif-Bold', 'italic' => 'DejaVuSerif-Italic', 'bold_italic' => 'DejaVuSerif-BoldItalic'];
        $mono = ['normal' => 'DejaVuSansMono', 'bold' => 'DejaVuSansMono-Bold', 'italic' => 'DejaVuSansMono-Oblique', 'bold_italic' => 'DejaVuSansMono-BoldOblique'];

        $families = [
            'sans-serif' => $sans, 'helvetica' => $sans, 'symbol' => $sans, 'zapfdingbats' => $sans,
            'serif' => $serif, 'times' => $serif, 'times-roman' => $serif,
            'monospace' => $mono, 'courier' => $mono, 'fixed' => $mono,
        ];

        $map = array_map(
            static fn (array $variants): array => array_map(static fn (string $file): string => $fonts.$file, $variants),
            $families,
        );

        $json = json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $target = $this->cacheDir.'/'.FontMetrics::USER_FONTS_FILE;

        if (is_file($target) && file_get_contents($target) === $json) {
            return;
        }

        $temporary = tempnam($this->cacheDir, 'fonts');

        if (false === $temporary || false === file_put_contents($temporary, $json) || !rename($temporary, $target)) {
            throw new \RuntimeException(\sprintf('The PDF font map "%s" cannot be written.', $target));
        }
    }
}
