<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Contao\CoreBundle\Util\LocaleUtil;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;

/**
 * Turns a document row and its snapshot into what the invoice template shows
 * — every figure formatted, every label decided, nothing left to compute.
 *
 * The template only lays out (8.1: "Die Vorlage gestaltet nur, sie liefert nie
 * Daten"). That also means the PDF and the accessible HTML version (4.3) are
 * the same data twice, not two calculations that could drift apart.
 *
 * ── Which columns ─────────────────────────────────────────────────────────
 *
 * Unit price and line total are GROSS, and the tax is broken down per rate
 * underneath — net, tax, gross (§ 11 Abs 2 UStG: amounts and tax separated by
 * rate). The gross line column is the one that adds up to the total, because
 * since C-7 grandTotal IS the sum of rounded line grosses, the figure the
 * buyer pressed the button on. Net columns per line would each be rounded on
 * their own and sum to netTotal, but the unit price then shows four places or
 * disagrees with its line.
 *
 * ── Version 1 ─────────────────────────────────────────────────────────────
 *
 * The invoices written before formatVersion 2 lack the issuer, the logo, the
 * payment details and the notes. They are shown with what they have and
 * NEVER completed from today's shop: an issuer read now is not the one who
 * issued then. Two things are normalised because the old form was wrong, not
 * incomplete: a missing lineType means `product` (C-7, F6), and the tax
 * summary is recomputed by the rule that adds up to taxTotal (C-8) — version
 * 1 stored raw four-place sums that could disagree with it by a cent.
 */
final class InvoiceView
{
    public function __construct(
        private readonly MoneyFormatter $money,
        private readonly DocumentPayloadBuilder $payloads,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param array<string, mixed> $document the tl_consho_document row, with the order's `orderLanguage`
     * @param array<string, mixed> $payload  the decoded snapshot
     *
     * @return array<string, mixed>
     */
    public function build(array $document, array $payload): array
    {
        /** @var array<string, string> $order */
        $order = \is_array($payload['order'] ?? null) ? $payload['order'] : [];
        /** @var list<array<string, string>> $items */
        $items = \is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];

        $version = (int) ($payload['formatVersion'] ?? 1);
        // Version 1 did not capture the language. The order's own column is
        // the fallback: it is written once at the purchase and never edited,
        // so it is the same fact the snapshot would have held — unlike the
        // issuer, which lives on a shop that changes.
        $language = (string) ($order['language'] ?? '');

        if ('' === $language) {
            $language = (string) ($document['orderLanguage'] ?? '');
        }
        $currency = (string) ($order['currency'] ?? '');
        $locale = LocaleUtil::formatAsLocale('' !== $language ? $language : 'en');

        $lines = [];

        // DL4: with two tax countries the rate alone does not say whose tax
        // it is — the country goes in front, on the lines and in the summary.
        $countries = \count(DocumentPayloadBuilder::taxCountries($items)) > 1;

        foreach ($items as $index => $item) {
            $rate = (string) ($item['taxRate'] ?? '0');
            $country = $countries && is_numeric($rate) && (float) $rate > 0 ? strtoupper((string) ($item['taxCountry'] ?? '')) : '';
            $lines[] = [
                'position' => $index + 1,
                'type' => '' !== ($item['lineType'] ?? '') ? $item['lineType'] : 'product',
                'name' => (string) ($item['productName'] ?? ''),
                'sku' => (string) ($item['sku'] ?? ''),
                'quantity' => $this->money->quantity((string) ($item['quantity'] ?? '0'), $language),
                'unitPrice' => $this->money->format($this->unitGross($item, $rate, $currency), $currency, $language),
                // WG3: a voucher sold not taxable has no rate, not "0 %".
                'taxRate' => PriceSource::NotTaxable->value === ($item['priceSource'] ?? '') ? '–' : ('' !== $country ? $country.' ' : '').$this->percent($rate, $locale),
                'total' => $this->money->format((string) ($item['lineTotal'] ?? '0'), $currency, $language),
                'reverseCharge' => 'reverseCharge' === ($item['priceSource'] ?? ''),
                // Abo-2 (P13): a subscription's conditions as frozen at the
                // purchase; '' on every other line and before version 6.
                'terms' => (string) ($item['subscriptionTerms'] ?? ''),
            ];
        }

        $taxes = [];

        foreach ($this->payloads->summariseTax($items, $currency) as $key => $entry) {
            [$country, $rate] = DocumentPayloadBuilder::splitTaxKey((string) $key);
            $taxes[] = [
                // WG3: the template names it "nicht steuerbar" (language file).
                'notTaxable' => DocumentPayloadBuilder::NOT_TAXABLE === (string) $key,
                'rate' => DocumentPayloadBuilder::NOT_TAXABLE === (string) $key ? '' : ('' !== $country ? $country.' ' : '').$this->percent($rate, $locale),
                'net' => $this->money->format($entry['net'], $currency, $language),
                'tax' => $this->money->format($entry['tax'], $currency, $language),
                'gross' => $this->money->format($entry['gross'], $currency, $language),
            ];
        }

        /** @var array<string, string> $issuer */
        $issuer = \is_array($payload['issuer'] ?? null) ? $payload['issuer'] : [];
        /** @var array{instructions?: string, details?: array<string, string>, giroCode?: string, dueDate?: string, payments?: mixed, outstanding?: mixed} $payment */
        $payment = \is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        // The frozen EPC payload (formatVersion 3) drawn as it was frozen —
        // nothing here rebuilds it from the bank details.
        $giroCode = \is_string($payment['giroCode'] ?? null) && '' !== $payment['giroCode'] ? $this->giroCode($payment['giroCode'], (string) ($document['documentNumber'] ?? '')) : null;
        /** @var array{footer?: string, reverseCharge?: string} $notes */
        $notes = \is_array($payload['notes'] ?? null) ? $payload['notes'] : [];

        return [
            'formatVersion' => $version,
            'language' => '' !== $language ? $language : 'en',
            'type' => (string) ($document['type'] ?? 'invoice'),
            'number' => (string) ($document['documentNumber'] ?? ''),
            'issuedAt' => $this->date((int) ($document['issuedAt'] ?? 0), $locale),
            'orderNumber' => (string) ($order['orderNumber'] ?? ''),
            'orderDate' => $this->date((int) ($order['createdAt'] ?? 0), $locale),
            // The invoice a cancellation refers to (C-10), as its snapshot
            // named it; null on every other document.
            'cancels' => \is_array($payload['cancels'] ?? null) ? [
                'number' => (string) ($payload['cancels']['number'] ?? ''),
                'date' => $this->date((int) ($payload['cancels']['issuedAt'] ?? 0), $locale),
            ] : null,
            // The invoice a correction refers to, and the withdrawal it
            // answers (D-3); null / '' on every other document.
            'corrects' => \is_array($payload['corrects'] ?? null) ? [
                'number' => (string) ($payload['corrects']['number'] ?? ''),
                'date' => $this->date((int) ($payload['corrects']['issuedAt'] ?? 0), $locale),
            ] : null,
            'withdrawal' => \is_string($payload['withdrawal'] ?? null) ? $payload['withdrawal'] : '',
            // A follow-up order's subscription and service period
            // (formatVersion 7, Abo-4); '' on every other document.
            'subscription' => (string) ($payload['subscription']['number'] ?? ''),
            'servicePeriod' => $this->period(\is_array($payload['subscription'] ?? null) ? $payload['subscription'] : null, $locale),
            'deliveryPeriod' => $this->period(\is_array($payload['delivery'] ?? null) ? $payload['delivery'] : null, $locale),
            // Which label goes with it (formatVersion 5, E-6b); older
            // snapshots only ever held the expected period.
            'deliveryKind' => \in_array($payload['delivery']['kind'] ?? null, ['delivered', 'partial'], true) ? $payload['delivery']['kind'] : 'expected',
            'deliveryFirst' => $this->period(\is_array($payload['delivery'] ?? null) ? ['from' => $payload['delivery']['from'] ?? '', 'to' => $payload['delivery']['from'] ?? ''] : null, $locale),
            'deliveryRest' => $this->period(\is_array($payload['delivery'] ?? null) ? ['from' => $payload['delivery']['to'] ?? '', 'to' => $payload['delivery']['to'] ?? ''] : null, $locale),
            // "Zahlbar bis" of a purchase on account (formatVersion 4, E-2);
            // one day read as a period of one day, printed in the same zone.
            'dueDate' => \is_string($payment['dueDate'] ?? null) ? $this->period(['from' => $payment['dueDate'], 'to' => $payment['dueDate']], $locale) : '',
            'issuer' => [
                'lines' => $this->filled([
                    trim(($issuer['company'] ?? '').' '.($issuer['legalForm'] ?? '')),
                    $issuer['street'] ?? '',
                    trim(($issuer['postal'] ?? '').' '.($issuer['city'] ?? '')),
                    $this->country($issuer['country'] ?? '', $locale),
                ]),
                'vatId' => (string) ($issuer['vatId'] ?? ''),
            ],
            'billing' => $this->filled([
                (string) ($order['company'] ?? ''),
                trim(($order['salutation'] ?? '').' '.($order['firstname'] ?? '').' '.($order['lastname'] ?? '')),
                (string) ($order['billingStreet'] ?? ''),
                trim(($order['billingPostal'] ?? '').' '.($order['billingCity'] ?? '')),
                $this->country($order['billingCountry'] ?? '', $locale),
            ]),
            'shipping' => $this->shippingAddress($order, $locale),
            'customerVatId' => $this->customerVatId($order),
            'lines' => $lines,
            'taxes' => $taxes,
            'netTotal' => $this->money->format((string) ($order['netTotal'] ?? '0'), $currency, $language),
            'taxTotal' => $this->money->format((string) ($order['taxTotal'] ?? '0'), $currency, $language),
            'grandTotal' => $this->money->format((string) ($order['grandTotal'] ?? '0'), $currency, $language),
            'shippingMethod' => (string) ($order['shippingMethodTitle'] ?? ''),
            'paymentMethod' => (string) ($order['paymentMethodTitle'] ?? ''),
            'paymentInstructions' => (string) ($payment['instructions'] ?? ''),
            'bankDetails' => \is_array($payment['details'] ?? null) ? $payment['details'] : [],
            'giroCode' => $giroCode,
            // What was paid before the invoice and what remains (formatVersion
            // 9, WG12); empty / '' where nothing was.
            'payments' => $this->payments($payment['payments'] ?? null, $currency, $language),
            'outstanding' => \is_string($payment['outstanding'] ?? null) ? $this->money->format($payment['outstanding'], $currency, $language) : '',
            // EPC069-12: what the code holds is also printed, so the payer can
            // compare — read out of the code itself (its eleventh element, the
            // unstructured remittance), so the two cannot differ.
            'paymentReference' => null !== $giroCode ? (explode("\n", $payment['giroCode'])[10] ?? '') : '',
            'footer' => (string) ($notes['footer'] ?? ''),
            'reverseChargeNote' => (string) ($notes['reverseCharge'] ?? ''),
        ];
    }

    /**
     * The payments frozen into the invoice (formatVersion 9), as the payment
     * row named them; `refund` is the one name Consho writes itself.
     *
     * @return list<array{label: string, refund: bool, amount: string}>
     */
    private function payments(mixed $payments, string $currency, string $language): array
    {
        if (!\is_array($payments)) {
            return [];
        }

        $shown = [];

        foreach ($payments as $payment) {
            if (!\is_array($payment) || !\is_string($payment['amount'] ?? null)) {
                continue;
            }

            $method = (string) ($payment['method'] ?? '');
            $shown[] = [
                'label' => 'refund' === $method ? '' : $method,
                'refund' => 'refund' === $method,
                'amount' => $this->money->format($payment['amount'], $currency, $language),
            ];
        }

        return $shown;
    }

    /**
     * The frozen code as an image — or, if it cannot be drawn, the invoice
     * without it and an entry in the system log. Not a failed invoice: the
     * plain-text bank details stay, and they are what the payment needs.
     */
    private function giroCode(string $payload, string $number): string|null
    {
        try {
            return GiroCode::dataUri($payload);
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('The Giro-Code of invoice %s could not be drawn and was left out: %s', $number, $e->getMessage()), ['exception' => $e]);

            return null;
        }
    }

    /**
     * The snapshot keeps the NET unit price (net is the anchor, C22). The
     * gross one is derived the way the basket derives it and rounded to the
     * currency — for display, not for any sum.
     *
     * @param array<string, string> $item
     */
    private function unitGross(array $item, string $rate, string $currency): string
    {
        $digits = '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;

        return (string) BigDecimal::of($item['unitPrice'] ?? '0')
            ->multipliedBy(BigDecimal::of($rate)->plus(100))
            ->dividedBy(100, 8, RoundingMode::HalfUp)
            ->toScale($digits, RoundingMode::HalfUp)
        ;
    }

    private function percent(string $rate, string $locale): string
    {
        $formatter = new \NumberFormatter($locale, \NumberFormatter::PERCENT);
        $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, 2);

        return (string) $formatter->format(BigDecimal::of($rate)->dividedBy(100, 6, RoundingMode::HalfUp)->toFloat());
    }

    private function date(int $timestamp, string $locale): string
    {
        if ($timestamp < 1) {
            return '';
        }

        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, date_default_timezone_get());

        return (string) $formatter->format($timestamp);
    }

    /**
     * "26.09.2026 – 01.10.2026", or one date when both ends meet. The dates
     * are the snapshot's; nothing is recounted from today.
     *
     * @param array{from?: string, to?: string}|null $period
     */
    private function period(array|null $period, string $locale): string
    {
        // A calendar date, not a moment: read and printed in ONE zone, or
        // midnight in Vienna becomes the evening before in UTC.
        $utc = new \DateTimeZone('UTC');
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($period['from'] ?? ''), $utc);
        $to = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($period['to'] ?? ''), $utc);

        if (false === $from || false === $to) {
            return '';
        }

        $formatter = new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC');
        $first = (string) $formatter->format($from);
        $last = (string) $formatter->format($to);

        return $first === $last ? $first : $first.' – '.$last;
    }

    /**
     * The buyer's VAT ID, unless VIES said it is invalid (Michael, 2026-09-24:
     * "UID so umsetzen"). An ID the register rejected would stand on the
     * invoice as if it were the buyer's — which is exactly what VIES said it
     * is not. Valid: printed. Unavailable: printed, because the buyer gave it
     * and the order was taxed anyway (C15). A version 1 snapshot carries no
     * status and prints what it has.
     *
     * @param array<string, string> $order
     */
    private function customerVatId(array $order): string
    {
        return 'invalid' === ($order['vatIdStatus'] ?? '') ? '' : (string) ($order['vatId'] ?? '');
    }

    private function country(string $code, string $locale): string
    {
        if ('' === $code) {
            return '';
        }

        return Countries::exists(strtoupper($code)) ? Countries::getName(strtoupper($code), $locale) : $code;
    }

    /**
     * Only when it differs — the same address twice is noise, and a reader
     * starts looking for the difference that is not there.
     *
     * @param array<string, string> $order
     *
     * @return list<string>
     */
    private function shippingAddress(array $order, string $locale): array
    {
        if ('' === ($order['shippingStreet'] ?? '')) {
            return [];
        }

        $shipping = $this->filled([
            (string) ($order['shippingCompany'] ?? ''),
            trim(($order['shippingFirstname'] ?? '').' '.($order['shippingLastname'] ?? '')),
            $order['shippingStreet'],
            trim(($order['shippingPostal'] ?? '').' '.($order['shippingCity'] ?? '')),
            $this->country($order['shippingCountry'] ?? '', $locale),
        ]);

        $billing = $this->filled([
            (string) ($order['company'] ?? ''),
            trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? '')),
            (string) ($order['billingStreet'] ?? ''),
            trim(($order['billingPostal'] ?? '').' '.($order['billingCity'] ?? '')),
            $this->country($order['billingCountry'] ?? '', $locale),
        ]);

        return $shipping === $billing ? [] : $shipping;
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function filled(array $lines): array
    {
        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => '' !== $line));
    }
}
