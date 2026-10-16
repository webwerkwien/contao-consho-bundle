<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Webwerkwien\ContaoConshoBundle\Document\Pdf\PdfRendererInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * A voucher as the buyer reads it (block Wertgutscheine, WG4, WG14): in the
 * mail, on the order page, and as a PDF to print or pass on.
 *
 * The code in full — this is the one place it is handed out, and a gift
 * voucher is meant to be given away (WG4; unlike a licence key, F20, which
 * the order page keeps out of mails). The balance as it stands now, not as
 * issued: the order page shows what is left.
 *
 * The issuer is the shop's invoice sender (its company), else the shop's
 * title — the voucher says who honours it.
 */
final class VoucherDocument
{
    public const TEMPLATE = '@Contao/document/consho_voucher.html.twig';

    public function __construct(
        private readonly Connection $connection,
        private readonly Environment $twig,
        private readonly TranslatorInterface $translator,
        private readonly PdfRendererInterface $pdf,
        private readonly MoneyFormatter $money,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return list<array{id: int, code: string, value: string, balance: string, validUntil: string, issuer: string, blocked: bool}>
     */
    public function view(array $ids, string $language): array
    {
        if ([] === $ids) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT v.*, s.title AS shopTitle, s.invoiceCompany, s.invoiceLegalForm
               FROM tl_consho_voucher v LEFT JOIN tl_consho_shop s ON s.id = v.shop
              WHERE v.id IN (?) ORDER BY v.id',
            [$ids],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER],
        );

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => VoucherCode::format((string) $row['code']),
            'value' => $this->money->format((string) $row['faceValue'], (string) $row['currency'], $language),
            'balance' => $this->money->format((string) $row['balance'], (string) $row['currency'], $language),
            'validUntil' => null === $row['validUntil'] || '' === (string) $row['validUntil'] ? '' : $this->day((int) $row['validUntil'], $language),
            // Stored encoded by Contao ("&amp;"); the PDF is Twig and escapes itself.
            'issuer' => html_entity_decode(
                '' !== trim((string) $row['invoiceCompany']) ? trim($row['invoiceCompany'].' '.$row['invoiceLegalForm']) : (string) $row['shopTitle'],
                \ENT_QUOTES | \ENT_HTML5,
                'UTF-8',
            ),
            'blocked' => (bool) $row['blocked'],
        ], $rows);
    }

    /**
     * The vouchers an order bought, for its page.
     *
     * @return list<array{id: int, code: string, value: string, balance: string, validUntil: string, issuer: string, blocked: bool}>
     */
    public function ofOrder(int $orderId, string $language): array
    {
        return $this->view(array_map('intval', $this->connection->fetchFirstColumn("SELECT id FROM tl_consho_voucher WHERE orderId = ? AND source = 'purchase' ORDER BY id", [$orderId])), $language);
    }

    /**
     * One PDF per voucher, named after its last group.
     *
     * @param list<int> $ids
     *
     * @return list<array{pdf: string, fileName: string}>
     */
    public function pdfs(array $ids, string $language): array
    {
        $previous = $this->switchLocale($language);

        try {
            $pdfs = [];
            // In the order's language (acceptance WG-7: "Gutschein-…" in an
            // English mail), letters and digits only.
            $name = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $this->translator->trans('CONSHO.voucher.title', [], 'contao_default')), '-');

            foreach ($this->view($ids, $language) as $voucher) {
                $html = $this->twig->render(self::TEMPLATE, ['voucher' => $voucher, 'language' => $language]);
                $pdfs[] = ['pdf' => $this->pdf->render($html), 'fileName' => ('' !== $name && 'CONSHO-voucher-title' !== $name ? $name : 'Gutschein').'-'.substr(str_replace('-', '', $voucher['code']), -4).'.pdf'];
            }

            return $pdfs;
        } finally {
            if (null !== $previous) {
                $this->switchLocale($previous);
            }
        }
    }

    private function day(int $timestamp, string $language): string
    {
        return (string) (new \IntlDateFormatter('' !== $language ? $language : 'de', \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get()))->format($timestamp);
    }

    private function switchLocale(string $locale): string|null
    {
        if ('' === $locale || !$this->translator instanceof LocaleAwareInterface) {
            return null;
        }

        $previous = $this->translator->getLocale();
        $this->translator->setLocale($locale);

        return $previous;
    }
}
