<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\EInvoice;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatTypeCodes;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdDocumentPdfMerger;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\ZugferdXsdValidator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;
use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;

/**
 * The ZUGFeRD XML of a document, profile EN 16931 (block R, R9–R12), built
 * from its snapshot alone — what the PDF says, the XML says.
 *
 *  - An invoice is type 380; a cancellation and a correction are credit
 *    notes (381) with the invoice they refer to (BG-3) and their amounts
 *    turned positive, as EN 16931 expects of a credit note.
 *  - A line at a negative amount (a discount, a reduction of a fee) has a
 *    negative QUANTITY: the price must not be negative (BR-27).
 *  - Shipping, fees and reductions are lines, not document-level charges,
 *    as they are on the PDF.
 *  - VAT category per line from what the snapshot froze: reverse charge is
 *    K for goods (intra-community supply) and AE otherwise, "nicht
 *    steuerbar" is O, 0 % outside the EU is G (export), any other 0 % Z,
 *    everything else S.
 *  - The tax breakdown sums the lines' frozen tax per category and rate;
 *    the test holds its total against the grand total the PDF prints.
 *
 * Returns null — and the PDF goes out without XML — where the snapshot does
 * not say e-invoice, where EN 16931 cannot express it (category O beside
 * another, BR-O-11 …), or where the XML fails its schema. An invoice is
 * never held back for its XML; the system log says why it has none.
 */
class ZugferdInvoice
{
    /**
     * The places of the document's currency (second review R, B3): the PDF
     * rounds to them (summariseTax), so the XML does too. Set per call.
     */
    private int $digits = 2;

    private const EXEMPTION = [
        'K' => 'VATEX-EU-IC',
        'AE' => 'VATEX-EU-AE',
        'G' => 'VATEX-EU-G',
        'O' => 'VATEX-EU-O',
    ];

    public function __construct(private readonly LoggerInterface|null $logger = null)
    {
    }

    /**
     * The PDF with the XML embedded, or the PDF as it was.
     *
     * @param array<string, mixed> $document the tl_consho_document row
     * @param array<string, mixed> $payload  its decoded snapshot
     */
    public function embed(string $pdf, array $document, array $payload): string
    {
        $xml = $this->xml($document, $payload);

        if (null === $xml) {
            return $pdf;
        }

        try {
            $merger = new ZugferdDocumentPdfMerger($xml, $pdf);
            $merger->generateDocument();

            return $merger->downloadString();
        } catch (\Throwable $exception) {
            $this->logger?->error(\sprintf('The e-invoice XML of %s could not be embedded: %s', (string) ($document['documentNumber'] ?? ''), $exception->getMessage()));

            return $pdf;
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $payload
     */
    public function xml(array $document, array $payload): string|null
    {
        if (true !== ($payload['eInvoice'] ?? false)) {
            return null;
        }

        $number = (string) ($document['documentNumber'] ?? '');
        $type = DocumentType::tryFrom((string) ($document['type'] ?? ''));

        if (!\in_array($type, [DocumentType::Invoice, DocumentType::Cancellation, DocumentType::CreditNote], true)) {
            return null;
        }

        $credit = DocumentType::Invoice !== $type;
        $sign = $credit ? -1 : 1;

        /** @var array<string, string> $order */
        $order = \is_array($payload['order'] ?? null) ? $payload['order'] : [];
        /** @var array<string, string> $issuer */
        $issuer = \is_array($payload['issuer'] ?? null) ? $payload['issuer'] : [];

        // The shop refuses the switch without them (EInvoiceListener); a
        // field emptied afterwards still must not produce a seller without
        // a name or a VAT ID (BR-6, BR-CO-9).
        if ('' === trim($issuer['company'] ?? '') || '' === trim($issuer['vatId'] ?? '') || '' === trim($issuer['country'] ?? '')) {
            $this->logger?->warning(\sprintf('%s has no e-invoice XML: the shop lacks its company, country or VAT ID.', $number));

            return null;
        }
        /** @var list<array<string, string>> $items */
        $items = \is_array($payload['items'] ?? null) ? array_values($payload['items']) : [];
        $currency = (string) ($order['currency'] ?? '');
        $this->digits = Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;

        // EN 16931 amounts have at most two decimals (BR-DEC-*).
        if ($this->digits > 2) {
            $this->logger?->warning(\sprintf('%s has no e-invoice XML: %s has more than two decimals, which EN 16931 cannot carry.', $number, $currency));

            return null;
        }

        $destination = strtoupper('' !== ($order['shippingCountry'] ?? '') ? $order['shippingCountry'] : (string) ($order['billingCountry'] ?? ''));
        $goodsUnderReverseCharge = [] !== array_filter($items, static fn (array $item): bool => PriceSource::ReverseCharge->value === ($item['priceSource'] ?? '') && ProductKind::Physical === ProductKind::stored($item['kind'] ?? ''));

        $categories = [];

        foreach ($items as $index => $item) {
            $categories[$index] = self::category($item, $destination, $goodsUnderReverseCharge);
        }

        if (\in_array('O', $categories, true) && [] !== array_diff($categories, ['O'])) {
            $this->logger?->warning(\sprintf('%s has no e-invoice XML: "nicht steuerbar" next to taxed lines cannot be expressed in EN 16931 (BR-O-11).', $number));

            return null;
        }

        $builder = ZugferdDocumentBuilder::CreateNew(ZugferdProfiles::PROFILE_EN16931);
        $builder->setDocumentInformation($number, $credit ? ZugferdInvoiceType::CREDITNOTE : ZugferdInvoiceType::INVOICE, self::date((int) ($document['issuedAt'] ?? 0)), $currency);

        $referenced = \is_array($payload['cancels'] ?? null) ? $payload['cancels'] : (\is_array($payload['corrects'] ?? null) ? $payload['corrects'] : null);

        if ($credit && null !== $referenced) {
            $builder->setDocumentInvoiceReferencedDocument((string) ($referenced['number'] ?? ''), null, self::date((int) ($referenced['issuedAt'] ?? 0)));
        }

        // BR-O-2: a document outside the scope of VAT names no VAT identifier.
        $this->parties($builder, $issuer, $order, [] !== array_diff($categories, ['O']));
        $this->delivery($builder, $payload, $order);

        foreach ($items as $index => $item) {
            $this->position($builder, $index + 1, $item, $categories[$index], $sign);
        }

        $totals = $this->taxes($builder, $items, $categories, $sign);

        $notes = \is_array($payload['notes'] ?? null) ? $payload['notes'] : [];

        if ('' !== trim((string) ($notes['reverseCharge'] ?? ''))) {
            $builder->addDocumentNote(trim((string) $notes['reverseCharge']));
        }

        $prepaid = BigDecimal::zero();

        foreach (\is_array($payload['payment']['payments'] ?? null) ? $payload['payment']['payments'] : [] as $payment) {
            $prepaid = $prepaid->plus((string) ($payment['amount'] ?? '0'));
        }

        $grand = $totals['net']->plus($totals['tax']);
        $this->payment($builder, $payload, $order, $credit, $grand->minus($prepaid)->isPositive());
        $builder->setDocumentSummation(
            $this->amount($grand),
            $this->amount($grand->minus($prepaid)),
            $this->amount($totals['net']),
            0.0,
            0.0,
            $this->amount($totals['net']),
            $this->amount($totals['tax']),
            null,
            $this->amount($prepaid),
        );

        $validator = new ZugferdXsdValidator($builder);
        $validator->validate();

        if ($validator->hasValidationErrors()) {
            $this->logger?->error(\sprintf('%s has no e-invoice XML: the schema refuses it (%s).', $number, implode(' | ', \array_slice($validator->validationErrors(), 0, 3))));

            return null;
        }

        return $builder->getContent();
    }

    /**
     * @param array<string, string> $item
     *
     * @internal public for the test only
     */
    public static function category(array $item, string $destination, bool $goodsUnderReverseCharge): string
    {
        $source = $item['priceSource'] ?? '';
        $rate = BigDecimal::of('' !== ($item['taxRate'] ?? '') ? $item['taxRate'] : '0');

        return match (true) {
            PriceSource::NotTaxable->value === $source => 'O',
            PriceSource::ReverseCharge->value === $source => 'product' === ($item['lineType'] ?? 'product')
                ? (ProductKind::Physical === ProductKind::stored($item['kind'] ?? '') ? 'K' : 'AE')
                : ($goodsUnderReverseCharge ? 'K' : 'AE'),
            $rate->isZero() && '' !== $destination && !TaxPlace::isEuMemberState($destination) => 'G',
            $rate->isZero() => 'Z',
            default => 'S',
        };
    }

    /**
     * @param array<string, string> $issuer
     * @param array<string, string> $order
     */
    private function parties(ZugferdDocumentBuilder $builder, array $issuer, array $order, bool $taxIds): void
    {
        $seller = trim(($issuer['company'] ?? '').' '.($issuer['legalForm'] ?? ''));
        $builder->setDocumentSeller($seller);

        if ($taxIds) {
            $builder->addDocumentSellerTaxRegistration('VA', (string) ($issuer['vatId'] ?? ''));
        }
        $builder->setDocumentSellerAddress(self::text($issuer['street'] ?? ''), null, null, self::text($issuer['postal'] ?? ''), self::text($issuer['city'] ?? ''), strtoupper((string) ($issuer['country'] ?? '')));

        if ('' !== ($issuer['email'] ?? '')) {
            $builder->setDocumentSellerContact($seller, null, null, null, $issuer['email']);
            $builder->setDocumentSellerCommunication('EM', $issuer['email']);
        }

        $name = trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? ''));
        $company = trim($order['company'] ?? '');
        $builder->setDocumentBuyer('' !== $company ? $company : $name);

        if ($taxIds && '' !== trim($order['vatId'] ?? '')) {
            $builder->addDocumentBuyerTaxRegistration('VA', trim($order['vatId']));
        }

        $builder->setDocumentBuyerAddress(self::text($order['billingStreet'] ?? ''), null, null, self::text($order['billingPostal'] ?? ''), self::text($order['billingCity'] ?? ''), strtoupper((string) ($order['billingCountry'] ?? '')));

        if ('' !== $company && '' !== $name) {
            $builder->setDocumentBuyerContact($name, null, null, null, self::text($order['email'] ?? ''));
        }

        if ('' !== ($order['email'] ?? '')) {
            $builder->setDocumentBuyerCommunication('EM', $order['email']);
        }
    }

    /**
     * The delivery (BG-13, BG-14): the day shipped, otherwise the period
     * expected or billed; and where it went — K and G need both (BR-IC-11,
     * BR-IC-12, BR-G-…).
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $order
     */
    private function delivery(ZugferdDocumentBuilder $builder, array $payload, array $order): void
    {
        $delivery = \is_array($payload['delivery'] ?? null) ? $payload['delivery'] : null;
        $subscription = \is_array($payload['subscription'] ?? null) ? $payload['subscription'] : null;

        if (null !== $subscription && '' !== ($subscription['from'] ?? '') && '' !== ($subscription['to'] ?? '')) {
            $builder->setDocumentBillingPeriod(self::day((string) $subscription['from']), self::day((string) $subscription['to']), null);
        } elseif (null !== $delivery && \in_array($delivery['kind'] ?? '', ['delivered', 'partial'], true) && '' !== ($delivery['from'] ?? '')) {
            $builder->setDocumentSupplyChainEvent(self::day((string) $delivery['from']));
        } elseif (null !== $delivery && '' !== ($delivery['from'] ?? '') && '' !== ($delivery['to'] ?? '')) {
            $builder->setDocumentBillingPeriod(self::day((string) $delivery['from']), self::day((string) $delivery['to']), null);
        }

        // Without a delivery address the goods went to the billing address —
        // K needs a deliver-to country either way (BR-IC-12).
        $prefix = '' !== ($order['shippingCountry'] ?? '') ? 'shipping' : 'billing';

        if ('' !== ($order[$prefix.'Country'] ?? '')) {
            $name = 'shipping' === $prefix ? trim(($order['shippingFirstname'] ?? '').' '.($order['shippingLastname'] ?? '')) : trim(($order['firstname'] ?? '').' '.($order['lastname'] ?? ''));
            $company = trim('shipping' === $prefix ? ($order['shippingCompany'] ?? '') : ($order['company'] ?? ''));
            $builder->setDocumentShipTo('' !== $company ? $company : ('' !== $name ? $name : null));
            $builder->setDocumentShipToAddress(self::text($order[$prefix.'Street'] ?? ''), null, null, self::text($order[$prefix.'Postal'] ?? ''), self::text($order[$prefix.'City'] ?? ''), strtoupper($order[$prefix.'Country']));
        }
    }

    /**
     * @param array<string, string> $item
     */
    private function position(ZugferdDocumentBuilder $builder, int $line, array $item, string $category, int $sign): void
    {
        $quantity = BigDecimal::of('' !== ($item['quantity'] ?? '') ? $item['quantity'] : '1');
        $net = BigDecimal::of('' !== ($item['lineTotal'] ?? '') ? $item['lineTotal'] : '0')->minus('' !== ($item['taxAmount'] ?? '') ? $item['taxAmount'] : '0')->multipliedBy($sign);
        $price = BigDecimal::of('' !== ($item['unitPrice'] ?? '') ? $item['unitPrice'] : '0')->multipliedBy($sign);

        // BR-27: no negative price — the quantity carries the sign.
        if ($price->isNegative()) {
            $price = $price->negated();
            $quantity = $quantity->negated();
        }

        $builder->addNewPosition((string) $line);
        $builder->setDocumentPositionProductDetails('' !== trim($item['productName'] ?? '') ? trim($item['productName']) : '-', null, '' !== trim($item['sku'] ?? '') ? trim($item['sku']) : null);
        $builder->setDocumentPositionNetPrice((float) (string) $price->toScale(4, RoundingMode::HalfUp));
        $builder->setDocumentPositionQuantity((float) (string) $quantity, ZugferdUnitCodes::REC20_PIECE);
        $builder->addDocumentPositionTax($category, ZugferdVatTypeCodes::VALUE_ADDED_TAX, (float) (string) self::rate($item));
        $builder->setDocumentPositionLineSummation($this->amount($net));
    }

    /**
     * The breakdown per category and rate (BG-23) by the rule the PDF prints
     * (DocumentPayloadBuilder::summariseTax, C-8): the ROUNDED line nets and
     * grosses summed, the tax their difference. So the XML states the PDF's
     * figures to the cent, and BT-106 is the sum of the rounded line nets
     * (BR-CO-10) — review R, F3: rounding the sums instead was a cent off.
     *
     * @param list<array<string, string>> $items
     * @param array<int, string>          $categories
     *
     * @return array{net: BigDecimal, tax: BigDecimal}
     */
    private function taxes(ZugferdDocumentBuilder $builder, array $items, array $categories, int $sign): array
    {
        $groups = [];

        foreach ($items as $index => $item) {
            $key = $categories[$index].'|'.self::rate($item);
            $gross = BigDecimal::of('' !== ($item['lineTotal'] ?? '') ? $item['lineTotal'] : '0');
            $lineNet = $gross->minus('' !== ($item['taxAmount'] ?? '') ? $item['taxAmount'] : '0');
            $groups[$key] ??= ['category' => $categories[$index], 'rate' => self::rate($item), 'net' => BigDecimal::zero(), 'gross' => BigDecimal::zero()];
            $groups[$key]['net'] = $groups[$key]['net']->plus($lineNet->toScale($this->digits, RoundingMode::HalfUp)->multipliedBy($sign));
            $groups[$key]['gross'] = $groups[$key]['gross']->plus($gross->toScale($this->digits, RoundingMode::HalfUp)->multipliedBy($sign));
        }

        $net = BigDecimal::zero();
        $tax = BigDecimal::zero();

        foreach ($groups as $group) {
            $groupNet = $group['net'];
            $groupTax = $group['gross']->minus($group['net']);
            $builder->addDocumentTax(
                $group['category'],
                ZugferdVatTypeCodes::VALUE_ADDED_TAX,
                (float) (string) $groupNet,
                (float) (string) $groupTax,
                (float) (string) $group['rate'],
                null,
                self::EXEMPTION[$group['category']] ?? null,
            );
            $net = $net->plus($groupNet);
            $tax = $tax->plus($groupTax);
        }

        return ['net' => $net, 'tax' => $tax];
    }

    /**
     * @param array<string, mixed>  $payload
     * @param array<string, string> $order
     */
    private function payment(ZugferdDocumentBuilder $builder, array $payload, array $order, bool $credit, bool $owed): void
    {
        $payment = \is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $details = \is_array($payment['details'] ?? null) ? $payment['details'] : [];
        $code = (string) ($payment['meansCode'] ?? 'ZZZ');
        $iban = str_replace(' ', '', (string) ($details['iban'] ?? ''));

        if ('58' === $code && '' !== $iban && !$credit) {
            $builder->addDocumentPaymentMean($code, null, null, null, null, null, $iban, '' !== ($details['accountHolder'] ?? '') ? (string) $details['accountHolder'] : null, null, '' !== ($details['bic'] ?? '') ? str_replace(' ', '', (string) $details['bic']) : null);
        } else {
            $builder->addDocumentPaymentMean($credit ? 'ZZZ' : $code);
        }

        /*
         * BR-CO-25: something owed needs a due date or payment terms. Only
         * "Auf Rechnung" names a date; prepayment states its method — the
         * title as the order froze it, in the order's language.
         */
        if (!$credit && '' !== ($payment['dueDate'] ?? '')) {
            $builder->addDocumentPaymentTerm(null, self::day((string) $payment['dueDate']));
        } elseif ($owed) {
            /*
             * A credit note asks nothing of the buyer, but its amount is "due"
             * all the same, and BR-CO-25 knows no document type (second review
             * R, B2): it states that it is refunded. In the order's language,
             * like the method's title (B6).
             */
            $german = str_starts_with((string) ($order['language'] ?? ''), 'de');
            $builder->addDocumentPaymentTerm(match (true) {
                $credit => $german ? 'Erstattung' : 'Refund',
                '' !== trim($order['paymentMethodTitle'] ?? '') => trim($order['paymentMethodTitle']),
                default => $german ? 'Vorkasse' : 'Payment in advance',
            });
        }
    }

    /**
     * @param array<string, string> $item
     */
    private static function rate(array $item): string
    {
        return (string) BigDecimal::of('' !== ($item['taxRate'] ?? '') ? $item['taxRate'] : '0')->toScale(2, RoundingMode::HalfUp);
    }

    private function amount(BigDecimal $amount): float
    {
        return (float) (string) $amount->toScale($this->digits, RoundingMode::HalfUp);
    }

    private static function date(int $timestamp): \DateTimeImmutable
    {
        return (new \DateTimeImmutable())->setTimestamp($timestamp > 0 ? $timestamp : time());
    }

    private static function day(string $ymd): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $ymd) ?: new \DateTimeImmutable($ymd);
    }

    private static function text(string $value): string|null
    {
        return '' !== trim($value) ? trim($value) : null;
    }
}
