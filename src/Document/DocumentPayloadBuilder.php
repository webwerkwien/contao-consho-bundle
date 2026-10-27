<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Symfony\Component\Intl\Currencies;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;

/**
 * Builds the snapshot a document keeps.
 *
 * Only values, never references: the document has to stand on its own years
 * later, when the product may be gone and the customer may have moved.
 *
 * That is why the two field lists below are allow-lists rather than a blanket
 * copy of the row. A blanket copy would carry `product` and `member` along, and
 * the first person to see an id in a payload will try to resolve it.
 */
final class DocumentPayloadBuilder
{
    private const ORDER_FIELDS = [
        'orderNumber', 'currency', 'salutation', 'firstname', 'lastname', 'company',
        'email', 'phone', 'vatId', 'vatIdRequestIdentifier',
        'billingStreet', 'billingPostal', 'billingCity', 'billingState', 'billingCountry',
        'shippingCompany', 'shippingFirstname', 'shippingLastname',
        'shippingStreet', 'shippingPostal', 'shippingCity', 'shippingState', 'shippingCountry',
        'netTotal', 'taxTotal', 'grandTotal',
        // Since formatVersion 2 (C-8): the language decides the wording of
        // the invoice, the order date belongs on it, and the two method
        // titles are the copies the order itself froze at the purchase.
        'language', 'createdAt', 'shippingMethodTitle', 'paymentMethodTitle',
        // The VIES result beside the VAT ID it belongs to: whether the ID is
        // printed depends on it (InvoiceView).
        'vatIdStatus',
    ];

    /**
     * A delivery note's part of the order (dev#2): no amounts, no payment, no
     * VAT ID — what a parcel needs, nothing an invoice would.
     */
    private const DELIVERY_NOTE_ORDER_FIELDS = [
        'orderNumber', 'salutation', 'firstname', 'lastname', 'company',
        'billingStreet', 'billingPostal', 'billingCity', 'billingState', 'billingCountry',
        'shippingCompany', 'shippingFirstname', 'shippingLastname',
        'shippingStreet', 'shippingPostal', 'shippingCity', 'shippingState', 'shippingCountry',
        'language', 'createdAt', 'shippingMethodTitle',
    ];

    /**
     * 🔴 `lineType` is in here since C-7 (F6).
     *
     * Before C-7 every line was an article and the list did not need to say
     * so. Now shipping, a payment fee and a payment discount are lines of
     * their own — and a document that cannot tell them apart prints "Parcel"
     * in the article table, between the products, with an SKU column left
     * empty beside it.
     *
     * Added NOW rather than with the invoice layout it is for (C-8), because
     * a snapshot is written once: an invoice issued today without this field
     * cannot be given it later. Invoices issued before predate line types,
     * so a reader has to treat a MISSING lineType as `product` —
     * which is what they were.
     *
     * `source` and `customerGroup` stay out. They are ids, and the first
     * person to see an id in a payload will try to resolve it years after the
     * row is gone — the reason these lists are allow-lists at all. The same
     * holds for `subscriptionRule` (Abo-2): the TERMS go in, as the buyer
     * read them, never the id of a rule that may be edited or gone.
     */
    private const ITEM_FIELDS = [
        'productName', 'sku', 'quantity', 'unitPrice', 'taxRate', 'taxAmount', 'lineTotal', 'priceSource',
        'lineType', 'subscriptionTerms', 'taxCountry',
        // Block R (R-2): what the line was, for the OSS threshold — a
        // service is taxed where the shop is, also on a correction.
        'kind',
        // Version 12 (EZ-3): sold as a single-purpose voucher.
        'voucherSinglePurpose',
    ];

    /**
     * What shape this snapshot is in, for whoever reads it years from now.
     *
     * 1 is every payload written before the number existed as well: a reader
     * that finds no formatVersion is looking at 1 minus `lineType`, and the
     * note above says how to read that.
     *
     * 2 (C-8) adds everything 8.1 asked for from the start and C-7 did not
     * yet write: the issuer with the shop's VAT ID, the logo as UUID plus
     * hash, the payment instructions and bank details, the operator's footer
     * and reverse charge note, the order's language and date, the expected
     * delivery period of an invoice issued before shipping — and a
     * `taxSummary` built as gross minus net per rate, rounded to the
     * currency.
     *
     * 3 (C-8, Giro-Code) adds `payment.giroCode` to an invoice whose payment
     * method asks for it: the finished EPC payload (GiroCode), not a switch,
     * so a regenerated invoice carries the same code whatever the code
     * builder does by then. Absent where there is no code.
     *
     * 4 (E-2) adds `payment.dueDate` (Y-m-d) to an invoice of a purchase on
     * account — the day it is payable by. Absent everywhere else.
     *
     * 5 (E-6b) adds `delivery.kind`: `expected` (the period before shipping),
     * `delivered` (the day everything went) or `partial` (the first parcel,
     * `to` the day the rest is expected). Without it, a period.
     *
     * 6 (Abo-2) adds `subscriptionTerms` to every item: the conditions of a
     * subscription line as frozen at the purchase, '' on every other line.
     * Absent before, which reads the same.
     *
     * 7 (Abo-4) adds `subscription` to the invoice of a follow-up order: the
     * subscription number and the service period as calendar days, `from`
     * and `to` (Y-m-d). Null on every other document, and absent before.
     *
     * 8 (Dienstleistung, DL4) adds `taxCountry` to every item: the country
     * whose rate the line carries — the shop's for a service, the delivery
     * country otherwise. Absent before, which reads as one country.
     *
     * 9 (Wertgutscheine, WG12) adds `payment.payments` (method and amount of
     * each payment made before the invoice, refunds negative) and
     * `payment.outstanding` (grand total minus their sum) to an invoice that
     * asks for payment and was preceded by one — DocumentIssuer writes both.
     * The Giro-Code and `dueDate` then ask for the outstanding amount, and
     * are absent where nothing is outstanding. Absent before, which reads as
     * "nothing paid yet".
     *
     * 10 (block R, R10, R12) adds `eInvoice` — true where the shop issues
     * e-invoices and the buyer is a business: the PDF then carries the
     * ZUGFeRD XML, and a cancellation or correction copies the flag from its
     * invoice —, `payment.meansCode` (UNTDID 4461, DbalDocumentSource),
     * `issuer.email` (the seller's contact) and `kind` on every item (also
     * on a correction's lines, for the OSS threshold since R-2). Absent
     * before, which reads as "no e-invoice".
     *
     * 11 (L-1) adds `delivery.advance` — true on an invoice issued before
     * shipping whose customer pays before the goods go out (prepayment,
     * online, vouchers): it then says it covers an advance payment (UStR 2000
     * Rz 1524). Absent on a purchase on account or cash on delivery, and
     * before; a version 10 snapshot with the flag prints no note.
     *
     * 13 (Wertgutscheine, EZ-4) adds `prepaid`: per single-purpose voucher
     * that paid, its masked name, the country and rate its sale was taxed at
     * and the net, tax and gross of what it paid, refunds to it deducted —
     * tax already charged when the voucher was sold, which the invoice
     * deducts instead of charging it again. Empty where none paid, and absent
     * before, which reads the same.
     *
     * 12 (Wertgutscheine, EZ-3) adds `voucherSinglePurpose` to every item:
     * 1 on a line that sold a single-purpose voucher, which the invoice notes
     * beside the line. Absent before, which reads as a multi-purpose one.
     *
     * InvoiceView reads every version; a version 1 snapshot is
     * shown with what it has, never completed from today's shop.
     *
     * A delivery note (dev#2) carries the same number but a shape of its
     * own, without a single amount — see deliveryNote().
     */
    private const FORMAT_VERSION = 13;

    /**
     * The key of summariseTax() for lines sold "nicht steuerbar" (WG3) —
     * read off `priceSource`, which every version already carries.
     */
    public const NOT_TAXABLE = 'notTaxable';

    /**
     * @param array<string, string>       $order
     * @param list<array<string, string>> $items
     * @param array{
     *     issuer?: array<string, string>,
     *     logo?: array{uuid: string, hash: string}|null,
     *     payment?: array{instructions: string, details: array<string, string>, giroCode?: bool, dueDays?: int|null, meansCode?: string, advance?: bool},
     *     notes?: array{footer: string, reverseCharge: string},
     *     eInvoice?: bool,
     *     deliveryDays?: array{min: int, max: int},
     *     delivery?: array{from: string, to: string, kind?: string, advance?: bool}|null,
     *     subscription?: array{number: string, from: string, to: string}|null,
     *     prepaid?: list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}>,
     * } $context what DocumentSourceInterface read from the shop and the method
     *
     * @return array{
     *     formatVersion: int,
     *     order: array<string, string>,
     *     items: list<array<string, string>>,
     *     taxSummary: array<string, array{net: string, tax: string, gross: string}>,
     *     issuer: array<string, string>,
     *     logo: array{uuid: string, hash: string}|null,
     *     payment: array{instructions: string, details: array<string, string>, meansCode: string},
     *     notes: array{footer: string, reverseCharge: string},
     *     delivery: array{from: string, to: string, kind?: string, advance?: bool}|null,
     *     subscription: array{number: string, from: string, to: string}|null,
     *     prepaid: list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}>,
     *     eInvoice: bool,
     * }
     */
    public function build(array $order, array $items, array $context = []): array
    {
        $capturedOrder = [];

        foreach (self::ORDER_FIELDS as $field) {
            $capturedOrder[$field] = (string) ($order[$field] ?? '');
        }

        $capturedItems = [];

        foreach ($items as $item) {
            $captured = [];

            foreach (self::ITEM_FIELDS as $field) {
                $captured[$field] = (string) ($item[$field] ?? '');
            }

            $capturedItems[] = $captured;
        }

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'order' => $capturedOrder,
            'items' => $capturedItems,
            'taxSummary' => $this->summariseTax($capturedItems, (string) ($order['currency'] ?? '')),
            'issuer' => $context['issuer'] ?? [],
            'logo' => $context['logo'] ?? null,
            // The expected delivery period (C-8), dates not a rule — see
            // DeliveryPeriod. Null where none is configured.
            'delivery' => $context['delivery'] ?? null,
            // Abo-4 (P31): which subscription, which period — frozen on the
            // order, copied here as the invoice states it.
            'subscription' => $context['subscription'] ?? null,
            // Version 13 (EZ-4): what single-purpose vouchers paid, already taxed.
            'prepaid' => $context['prepaid'] ?? [],
            // Without the Giro-Code wish: the code needs the document number,
            // which is allocated after this snapshot is built. DocumentIssuer
            // adds the finished payload to an invoice.
            'payment' => [
                'instructions' => $context['payment']['instructions'] ?? '',
                'details' => $context['payment']['details'] ?? [],
                'meansCode' => $context['payment']['meansCode'] ?? 'ZZZ',
            ],
            /*
             * Block R (R10): only to a business — a net price group or a
             * verified VAT ID, frozen on the order. A consumer gets an
             * e-invoice only with consent (§ 14 Abs. 1 UStG), which Consho
             * does not ask for.
             */
            'eInvoice' => ($context['eInvoice'] ?? false) && '1' === (string) ($order['businessCustomer'] ?? ''),
            'notes' => [
                'footer' => $context['notes']['footer'] ?? '',

                /*
                 * Only where it applies. A note on the transfer of tax
                 * liability printed on an ordinary invoice is not harmless:
                 * it states something about this sale that is not true.
                 */
                'reverseCharge' => $this->hasReverseCharge($capturedItems) ? ($context['notes']['reverseCharge'] ?? '') : '',
            ],
        ];
    }

    /**
     * The snapshot of a cancellation, built from the snapshot of the invoice
     * it cancels (7.5) — never from the order, which a change of payment
     * method is about to rewrite. An invoice does not change, so its
     * counterpart must not say anything the invoice did not.
     *
     * Every AMOUNT turns its sign, the quantity does not: one piece at minus
     * ten, not minus one piece at minus ten, or the two signs would cancel out
     * in quantity × price. The tax summary is negated as stored rather than
     * recomputed, so it mirrors the invoice to the cent whatever rule the
     * invoice was summarised by.
     *
     * No payment block, no Giro-Code, no delivery period: a cancellation asks
     * for nothing. The issuer, the logo and the notes stay as the invoice had
     * them — the cancellation comes from the same issuer, not from today's.
     *
     * The shape stays the invoice's: same formatVersion, plus `cancels`.
     * A reader that knows the version knows everything but that one key.
     *
     * @param array<string, mixed> $invoice the decoded snapshot of the invoice
     *
     * @return array<string, mixed>
     */
    public function cancellationOf(array $invoice, string $invoiceNumber, int $invoiceIssuedAt): array
    {
        $payload = $invoice;

        /** @var array<string, string> $order */
        $order = \is_array($invoice['order'] ?? null) ? $invoice['order'] : [];

        foreach (['netTotal', 'taxTotal', 'grandTotal'] as $field) {
            $order[$field] = $this->negated((string) ($order[$field] ?? '0'));
        }

        $items = [];

        foreach (\is_array($invoice['items'] ?? null) ? $invoice['items'] : [] as $item) {
            foreach (['unitPrice', 'taxAmount', 'lineTotal'] as $field) {
                $item[$field] = $this->negated((string) ($item[$field] ?? '0'));
            }

            $items[] = $item;
        }

        $summary = [];

        foreach (\is_array($invoice['taxSummary'] ?? null) ? $invoice['taxSummary'] : [] as $rate => $entry) {
            $summary[$rate] = array_map(fn ($amount): string => $this->negated((string) $amount), $entry);
        }

        // EZ-4: the deduction is reversed with the lines it was taken from.
        $prepaid = [];

        foreach (\is_array($invoice['prepaid'] ?? null) ? $invoice['prepaid'] : [] as $entry) {
            foreach (['net', 'tax', 'gross'] as $field) {
                $entry[$field] = $this->negated((string) ($entry[$field] ?? '0'));
            }

            $prepaid[] = $entry;
        }

        $payload['order'] = $order;
        $payload['items'] = $items;
        $payload['taxSummary'] = $summary;
        $payload['prepaid'] = $prepaid;
        $payload['payment'] = ['instructions' => '', 'details' => []];
        $payload['delivery'] = null;
        $payload['cancels'] = ['number' => $invoiceNumber, 'issuedAt' => (string) $invoiceIssuedAt];

        return $payload;
    }

    /**
     * The snapshot of an invoice correction (D-3), built from the invoice it
     * corrects — issuer, customer, logo and notes as the invoice had them,
     * the lines those of the refund.
     *
     * The lines arrive with their amounts negative and their quantities
     * positive, the cancellation's rule. The tax summary is computed from
     * them the way an invoice's is (gross minus net per rate, rounded to the
     * currency), and the three totals are that summary's sums — so the
     * grand total is exactly the amount refunded, to the cent.
     *
     * `corrects` names the invoice, `withdrawal` the withdrawal it answers.
     *
     * `prepaid` is not the invoice's (EZ-4): only what this refund books back
     * onto single-purpose vouchers, negative — the deduction it takes back.
     *
     * @param array<string, mixed>        $invoice the decoded snapshot of the invoice
     * @param list<array<string, string>> $lines
     * @param list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}> $prepaid
     *
     * @return array<string, mixed>
     */
    public function correctionOf(array $invoice, string $invoiceNumber, int $invoiceIssuedAt, array $lines, string $withdrawalNumber, array $prepaid = []): array
    {
        $payload = $invoice;

        /** @var array<string, string> $order */
        $order = \is_array($invoice['order'] ?? null) ? $invoice['order'] : [];

        $items = [];

        foreach ($lines as $line) {
            $item = [];

            foreach (self::ITEM_FIELDS as $field) {
                $item[$field] = (string) ($line[$field] ?? '');
            }

            $items[] = $item;
        }

        $summary = $this->summariseTax($items, (string) ($order['currency'] ?? ''));
        $net = BigDecimal::zero();
        $tax = BigDecimal::zero();
        $gross = BigDecimal::zero();

        foreach ($summary as $entry) {
            $net = $net->plus($entry['net']);
            $tax = $tax->plus($entry['tax']);
            $gross = $gross->plus($entry['gross']);
        }

        $order['netTotal'] = (string) $net;
        $order['taxTotal'] = (string) $tax;
        $order['grandTotal'] = (string) $gross;

        $payload['order'] = $order;
        $payload['items'] = $items;
        $payload['taxSummary'] = $summary;
        $payload['prepaid'] = $prepaid;
        $payload['payment'] = ['instructions' => '', 'details' => []];
        $payload['delivery'] = null;
        $payload['corrects'] = ['number' => $invoiceNumber, 'issuedAt' => (string) $invoiceIssuedAt];
        $payload['withdrawal'] = $withdrawalNumber;

        unset($payload['cancels']);

        return $payload;
    }

    /**
     * The snapshot of a delivery note (dev#2): what is in THIS parcel, and
     * what follows separately — never a price.
     *
     * The order part keeps the names and both addresses (the billing one is
     * where a parcel goes when the order has no delivery address), the order
     * number and date, the language and the shipping method — the same keys
     * as on an invoice, so OrderAnonymizer finds them. Issuer, logo and footer
     * as the shop has them today, like an invoice issued today.
     *
     * `partial` is frozen with the rest: a note printed for the first parcel
     * keeps saying what was still to come then, whatever followed.
     *
     * @param array<string, string>                                          $order
     * @param array{issuer?: array<string, string>, logo?: array{uuid: string, hash: string}|null, notes?: array{footer?: string}} $context
     * @param list<array{productName: string, sku: string, quantity: string}> $lines
     * @param list<array{productName: string, sku: string, quantity: string}> $rest
     * @param array{shippedAt: string, carrier: string, trackingNumber: string} $shipment shippedAt as Y-m-d
     *
     * @return array<string, mixed>
     */
    public function deliveryNote(array $order, array $context, array $lines, array $rest, array $shipment): array
    {
        $capturedOrder = [];

        foreach (self::DELIVERY_NOTE_ORDER_FIELDS as $field) {
            $capturedOrder[$field] = (string) ($order[$field] ?? '');
        }

        return [
            'formatVersion' => self::FORMAT_VERSION,
            'order' => $capturedOrder,
            'items' => $lines,
            'rest' => $rest,
            'partial' => [] !== $rest,
            'shipment' => $shipment,
            'issuer' => $context['issuer'] ?? [],
            'logo' => $context['logo'] ?? null,
            'notes' => ['footer' => $context['notes']['footer'] ?? '', 'reverseCharge' => ''],
            'eInvoice' => false,
        ];
    }

    /**
     * The same places, the other sign. BigDecimal keeps the scale, so
     * "12.00" becomes "-12.00" and "0.0000" stays "0.0000" — a minus zero on
     * a cancellation would read as an error.
     */
    private function negated(string $amount): string
    {
        return (string) BigDecimal::of('' !== $amount ? $amount : '0')->negated();
    }

    /**
     * @param list<array<string, string>> $items
     */
    private function hasReverseCharge(array $items): bool
    {
        foreach ($items as $item) {
            if (PriceSource::ReverseCharge->value === $item['priceSource']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tax per RATE, not one figure for the document.
     *
     * An order carrying the standard and the reduced rate at once is ordinary
     * in food and book retail, and an invoice that adds the two into a single
     * tax line is not a valid invoice.
     *
     * The arithmetic runs on brick/math for the same reason it does everywhere
     * else here: summed as floats, a column of net amounts drifts in the last
     * decimal and the invoice no longer adds up to its own total.
     *
     * ── Gross minus net, PER RATE ─────────────────
     *
     * The order sums ROUNDED line grosses, because that is the figure the
     * buyer pressed the button on, and takes taxTotal as the difference to
     * the sum of rounded line nets (C-7, F1).
     * Until C-8 this method summed the raw line taxes instead and could
     * exceed taxTotal by a cent per rate: an invoice contradicting its own
     * tax total.
     *
     * Now it applies the order's rule once per rate: net and gross from the
     * SAME rounded lines the order summed, tax as their difference. Summed
     * over all rates that is exactly grandTotal − netTotal = taxTotal — no
     * remainder to hand to a rate, and no rule for which rate gets it.
     *
     * A rate's tax can then differ by a cent from its net times the rate.
     * That is inherent in rounding per line, and it is the same cent the
     * order already carries in taxTotal; § 11 Abs 2 UStG asks for net and tax
     * separated by rate, which this is.
     *
     * Rounded to the currency's own places, as the order is. An unknown or
     * empty currency falls back to two, the same as Symfony's own default.
     *
     * ── Per country as well, where there are two (DL4) ─
     *
     * A service is taxed in the shop's country, the goods beside it in the
     * customer's: one invoice can carry French 20 % on an e-book and
     * Austrian 20 % on a consultation. Summed by rate alone that is one line
     * "20 %" — the total right, the split per country gone. So where the
     * taxed lines (rate above zero) name more than one `taxCountry`, the key
     * is `COUNTRY:rate`; otherwise it stays the rate, as on every invoice
     * before. A zero rate is never split: reverse charge has no country's
     * tax to show.
     *
     * @param list<array<string, string>> $items
     *
     * @return array<string, array{net: string, tax: string, gross: string}> keyed by rate, or `COUNTRY:rate` (see splitTaxKey()), or NOT_TAXABLE
     */
    public function summariseTax(array $items, string $currency): array
    {
        $digits = $this->digitsOf($currency);
        $summary = [];
        $split = \count(self::taxCountries($items)) > 1;

        foreach ($items as $item) {
            // Four places whatever the driver returns (MariaDB "20.0000",
            // SQLite "20"): the key must not depend on it.
            $rate = (string) BigDecimal::of(is_numeric($item['taxRate'] ?? null) ? (string) $item['taxRate'] : '0')->toScale(4, RoundingMode::HalfUp);
            $country = strtoupper((string) ($item['taxCountry'] ?? ''));

            if ($split && '' !== $country && BigDecimal::of($rate)->isPositive()) {
                $rate = $country.':'.$rate;
            }

            // A voucher sold not taxable (WG3) is no "0 %": a line of its own.
            if (PriceSource::NotTaxable->value === ($item['priceSource'] ?? '')) {
                $rate = self::NOT_TAXABLE;
            }

            $gross = BigDecimal::of($item['lineTotal']);
            $net = $gross->minus(BigDecimal::of($item['taxAmount']));

            if (!isset($summary[$rate])) {
                $summary[$rate] = ['net' => BigDecimal::zero(), 'gross' => BigDecimal::zero()];
            }

            $summary[$rate]['net'] = $summary[$rate]['net']->plus($net->toScale($digits, RoundingMode::HalfUp));
            $summary[$rate]['gross'] = $summary[$rate]['gross']->plus($gross->toScale($digits, RoundingMode::HalfUp));
        }

        // Ascending rate, so the reduced rate is listed before the standard one
        // — the order every invoice in the DACH region uses. With countries,
        // by country first, then rate; a key without one (zero) leads.
        uksort($summary, static function (string|int $a, string|int $b): int {
            // "nicht steuerbar" last, after every rate.
            if (self::NOT_TAXABLE === $a || self::NOT_TAXABLE === $b) {
                return (self::NOT_TAXABLE === $a) <=> (self::NOT_TAXABLE === $b);
            }

            [$countryA, $rateA] = self::splitTaxKey((string) $a);
            [$countryB, $rateB] = self::splitTaxKey((string) $b);

            return strcmp($countryA, $countryB) ?: BigDecimal::of($rateA)->compareTo($rateB);
        });

        return array_map(
            static fn (array $entry): array => [
                'net' => (string) $entry['net'],
                'tax' => (string) $entry['gross']->minus($entry['net']),
                'gross' => (string) $entry['gross'],
            ],
            $summary,
        );
    }

    /**
     * The countries whose tax an invoice carries: the `taxCountry` of every
     * line with a rate above zero, upper-case, unique, sorted.
     *
     * @param list<array<string, string>> $items
     *
     * @return list<string>
     */
    public static function taxCountries(array $items): array
    {
        $countries = [];

        foreach ($items as $item) {
            $country = strtoupper((string) ($item['taxCountry'] ?? ''));

            if ('' !== $country && is_numeric($item['taxRate'] ?? null) && BigDecimal::of((string) $item['taxRate'])->isPositive()) {
                $countries[$country] = true;
            }
        }

        $countries = array_keys($countries);
        sort($countries);

        return $countries;
    }

    /**
     * A key of summariseTax() as country and rate; '' for a key without one.
     *
     * @return array{string, string}
     */
    public static function splitTaxKey(string $key): array
    {
        return str_contains($key, ':') ? explode(':', $key, 2) + ['', '0'] : ['', $key];
    }

    private function digitsOf(string $currency): int
    {
        return '' !== $currency && Currencies::exists($currency) ? Currencies::getFractionDigits($currency) : 2;
    }
}
