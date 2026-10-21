<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Contao\CoreBundle\Util\LocaleUtil;
use Symfony\Component\Intl\Countries;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * Turns a delivery note and its snapshot (dev#2) into what its template
 * shows — formatted, decided, nothing left to compute. InvoiceView's rule,
 * for a document without a single amount.
 *
 * The address is the delivery address, or the billing address where the
 * order has none: a delivery note names where the parcel goes, once.
 */
final class DeliveryNoteView
{
    public function __construct(private readonly MoneyFormatter $money)
    {
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
        $language = (string) ($order['language'] ?? '');

        if ('' === $language) {
            $language = (string) ($document['orderLanguage'] ?? '');
        }

        $language = '' !== $language ? $language : 'en';
        $locale = LocaleUtil::formatAsLocale($language);

        /** @var array<string, string> $issuer */
        $issuer = \is_array($payload['issuer'] ?? null) ? $payload['issuer'] : [];
        /** @var array<string, string> $shipment */
        $shipment = \is_array($payload['shipment'] ?? null) ? $payload['shipment'] : [];

        return [
            'language' => $language,
            'type' => (string) ($document['type'] ?? DocumentType::DeliveryNote->value),
            'number' => (string) ($document['documentNumber'] ?? ''),
            'issuedAt' => $this->date((int) ($document['issuedAt'] ?? 0), $locale),
            'orderNumber' => (string) ($order['orderNumber'] ?? ''),
            'orderDate' => $this->date((int) ($order['createdAt'] ?? 0), $locale),
            'shippedAt' => $this->day((string) ($shipment['shippedAt'] ?? ''), $locale),
            'carrier' => (string) ($shipment['carrier'] ?? ''),
            'trackingNumber' => (string) ($shipment['trackingNumber'] ?? ''),
            'shippingMethod' => (string) ($order['shippingMethodTitle'] ?? ''),
            'partial' => true === ($payload['partial'] ?? false),
            'issuer' => $this->filled([
                trim(($issuer['company'] ?? '').' '.($issuer['legalForm'] ?? '')),
                $issuer['street'] ?? '',
                trim(($issuer['postal'] ?? '').' '.($issuer['city'] ?? '')),
                $this->country($issuer['country'] ?? '', $locale),
            ]),
            'address' => $this->address($order, $locale),
            'lines' => $this->lines($payload['items'] ?? null, $language),
            'rest' => $this->lines($payload['rest'] ?? null, $language),
            'footer' => (string) ($payload['notes']['footer'] ?? ''),
        ];
    }

    /**
     * @return list<array{position: int, name: string, sku: string, quantity: string}>
     */
    private function lines(mixed $items, string $language): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $lines = [];

        foreach (array_values($items) as $index => $item) {
            if (!\is_array($item)) {
                continue;
            }

            $lines[] = [
                'position' => $index + 1,
                'name' => (string) ($item['productName'] ?? ''),
                'sku' => (string) ($item['sku'] ?? ''),
                'quantity' => $this->money->quantity((string) ($item['quantity'] ?? '0'), $language),
            ];
        }

        return $lines;
    }

    /**
     * @param array<string, string> $order
     *
     * @return list<string>
     */
    private function address(array $order, string $locale): array
    {
        if ('' !== ($order['shippingStreet'] ?? '')) {
            return $this->filled([
                (string) ($order['shippingCompany'] ?? ''),
                trim(($order['shippingFirstname'] ?? '').' '.($order['shippingLastname'] ?? '')),
                $order['shippingStreet'],
                trim(($order['shippingPostal'] ?? '').' '.($order['shippingCity'] ?? '')),
                $this->country($order['shippingCountry'] ?? '', $locale),
            ]);
        }

        return $this->filled([
            (string) ($order['company'] ?? ''),
            trim(($order['salutation'] ?? '').' '.($order['firstname'] ?? '').' '.($order['lastname'] ?? '')),
            (string) ($order['billingStreet'] ?? ''),
            trim(($order['billingPostal'] ?? '').' '.($order['billingCity'] ?? '')),
            $this->country($order['billingCountry'] ?? '', $locale),
        ]);
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
     * A calendar day (Y-m-d) read and printed in ONE zone — see InvoiceView::period().
     */
    private function day(string $day, string $locale): string
    {
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $day, $utc);

        if (false === $date) {
            return '';
        }

        return (string) (new \IntlDateFormatter($locale, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::NONE, 'UTC'))->format($date);
    }

    private function country(string $code, string $locale): string
    {
        if ('' === $code) {
            return '';
        }

        return Countries::exists(strtoupper($code)) ? Countries::getName(strtoupper($code), $locale) : $code;
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
