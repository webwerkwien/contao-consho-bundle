<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\Filesystem\Dbafs\UnableToResolveUuidException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemException;
use Contao\CoreBundle\Filesystem\VirtualFilesystemInterface;
use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;
use Webwerkwien\ContaoConshoBundle\Checkout\MethodSettings;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReaderInterface;
use Webwerkwien\ContaoConshoBundle\Payment\CashOnDeliveryHandler;
use Webwerkwien\ContaoConshoBundle\Payment\InvoicePaymentHandler;
use Webwerkwien\ContaoConshoBundle\Payment\PrepaymentHandler;
use Webwerkwien\ContaoConshoBundle\Translation\ShopLanguages;
use Webwerkwien\ContaoConshoBundle\Translation\TranslationStorage;

/**
 * Reads the invoice context of an order (8.1) from the shop and the payment
 * method, at the moment the document is issued.
 *
 * Everything here is COPIED into the snapshot. The shop may move tomorrow,
 * the bank may change, the operator may rewrite the footer — an invoice
 * issued today keeps what was true today.
 */
final class DbalDocumentSource implements DocumentSourceInterface
{
    private const ISSUER_FIELDS = [
        'invoiceCompany' => 'company',
        'invoiceLegalForm' => 'legalForm',
        'invoiceStreet' => 'street',
        'invoicePostal' => 'postal',
        'invoiceCity' => 'city',
        'invoiceCountry' => 'country',
        'vatId' => 'vatId',
        // Block R (R12): the seller's contact on an e-invoice.
        'invoiceContactEmail' => 'email',
    ];

    /**
     * The bank details of the core's prepayment (C-2). Read by name rather
     * than asked of the handler: the handler hands its details out only in
     * start(), which needs a payment attempt, and an invoice is not one. A
     * module that wants more on the invoice has the method's instructions,
     * which every method carries.
     */
    private const BANK_DETAILS = ['accountHolder', 'iban', 'bic', 'bankName'];

    public function __construct(
        private readonly Connection $connection,
        private readonly TranslationStorage $translations,
        private readonly ShopLanguages $languages,
        #[Autowire(service: 'contao.filesystem.virtual.files')]
        private readonly VirtualFilesystemInterface $files,
        private readonly OrderBalanceReaderInterface $balances,
    ) {
    }

    public function contextFor(array $order): array
    {
        $shopId = (int) ($order['shop'] ?? 0);
        $language = (string) ($order['language'] ?? '');
        $shop = $this->connection->fetchAssociative('SELECT * FROM tl_consho_shop WHERE id = ?', [$shopId]) ?: [];

        $issuer = [];

        foreach (self::ISSUER_FIELDS as $column => $key) {
            $issuer[$key] = self::plain((string) ($shop[$column] ?? ''));
        }

        $fallback = $shopId > 0 ? $this->languages->fallbackOf($shopId) : '';

        return [
            'issuer' => $issuer,
            'logo' => $this->logo($shop['invoiceLogo'] ?? null),
            'payment' => $this->payment((int) ($order['paymentMethod'] ?? 0), $language, $fallback),
            'notes' => [
                'footer' => self::plain($this->translated('tl_consho_shop', $shopId, 'invoiceFooter', (string) ($shop['invoiceFooter'] ?? ''), $language, $fallback)),
                'reverseCharge' => self::plain($this->translated('tl_consho_shop', $shopId, 'invoiceReverseChargeNote', (string) ($shop['invoiceReverseChargeNote'] ?? ''), $language, $fallback)),
            ],
            // The rule, not the period: the period depends on the day of
            // issue, which DocumentIssuer knows and this does not.
            'deliveryDays' => ['min' => (int) ($shop['deliveryDaysMin'] ?? 0), 'max' => (int) ($shop['deliveryDaysMax'] ?? 0)],
            'shipment' => $this->shipment($order, $shop),
            'subscription' => $this->subscription($order),
            'payments' => $this->payments((int) ($order['id'] ?? 0)),
            'prepaid' => $this->prepaid((int) ($order['id'] ?? 0)),
            // Block R (R10): the shop's switch; whether THIS invoice carries
            // the XML the payload builder decides (business customers only).
            'eInvoice' => (bool) ($shop['eInvoice'] ?? false),
        ];
    }

    /**
     * What has been paid on the order so far (WG12): a voucher at the
     * purchase, a part payment before the invoice "on shipment". `method` as
     * the payment row names it — the method's title, or `refund`.
     *
     * @return list<array{method: string, amount: string}>
     */
    private function payments(int $orderId): array
    {
        if ($orderId < 1) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative('SELECT method, amount FROM tl_consho_payment WHERE pid = ? ORDER BY receivedAt, id', [$orderId]);

        // Four places whatever the database returns: MySQL hands back
        // "-1.0000", SQLite "-1" — the snapshot
        // keeps one shape.
        return array_map(static fn (array $row): array => ['method' => (string) $row['method'], 'amount' => (string) BigDecimal::of((string) $row['amount'])->toScale(4)], $rows);
    }

    /**
     * What single-purpose vouchers paid on the order (EZ-4): per voucher, its
     * payments less what went back to it, with the tax their sale already
     * carried. Named as the payment names it ("Gutschein …MDSN"), never by
     * the whole code. A voucher with nothing left paid drops out.
     *
     * @return list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}>
     */
    private function prepaid(int $orderId): array
    {
        if ($orderId < 1) {
            return [];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, reference, method, amount, prepaidTaxRate, prepaidTaxCountry, prepaidTax FROM tl_consho_payment WHERE pid = ? AND prepaidTaxRate > 0 ORDER BY receivedAt, id',
            [$orderId],
        );

        $vouchers = [];

        foreach ($rows as $row) {
            // The anonymisation empties the reference: such a payment stands on
            // its own rather than merging vouchers, rates and countries.
            $key = '' !== (string) $row['reference'] ? (string) $row['reference'] : 'payment:'.$row['id'];
            $amount = BigDecimal::of((string) $row['amount']);

            // The redemption names the voucher; a refund to it is a counter-entry.
            $vouchers[$key] ??= ['label' => '', 'country' => strtoupper((string) $row['prepaidTaxCountry']), 'rate' => (string) BigDecimal::of((string) $row['prepaidTaxRate'])->toScale(4), 'gross' => BigDecimal::zero(), 'tax' => BigDecimal::zero()];

            if ($amount->isPositive() && '' === $vouchers[$key]['label']) {
                $vouchers[$key]['label'] = (string) $row['method'];
            }

            $vouchers[$key]['gross'] = $vouchers[$key]['gross']->plus($amount);
            $vouchers[$key]['tax'] = $vouchers[$key]['tax']->plus((string) $row['prepaidTax']);
        }

        $prepaid = [];

        foreach ($vouchers as $voucher) {
            if (!$voucher['gross']->isPositive()) {
                continue;
            }

            $prepaid[] = [
                'label' => $voucher['label'],
                'country' => $voucher['country'],
                'rate' => $voucher['rate'],
                'net' => (string) $voucher['gross']->minus($voucher['tax'])->toScale(4),
                'tax' => (string) $voucher['tax']->toScale(4),
                'gross' => (string) $voucher['gross']->toScale(4),
            ];
        }

        return $prepaid;
    }

    /**
     * The subscription and the service period of a follow-up order (Abo-4,
     * P31), null for a purchase. The period from the ORDER, where it is
     * frozen — the subscription has moved on by the time anyone reprints.
     * Calendar days in the installation's zone, like the delivery period.
     *
     * @param array<string, string> $order
     *
     * @return array{number: string, from: string, to: string}|null
     */
    private function subscription(array $order): array|null
    {
        $id = (int) ($order['subscription'] ?? 0);
        $from = $order['servicePeriodFrom'] ?? null;
        $until = $order['servicePeriodUntil'] ?? null;

        if ($id <= 0 || null === $from || '' === $from || null === $until || '' === $until) {
            return null;
        }

        $zone = new \DateTimeZone(date_default_timezone_get());
        $day = static fn (string $timestamp): string => (new \DateTimeImmutable('@'.(int) $timestamp))->setTimezone($zone)->format('Y-m-d');

        return [
            'number' => (string) $this->connection->fetchOne('SELECT subscriptionNumber FROM tl_consho_subscription WHERE id = ?', [$id]),
            'from' => $day($from),
            'to' => $day($until),
        ];
    }

    /**
     * The first reported parcel of the order, for an invoice issued at or
     * after shipment (E-6b): its day, whether everything has gone, and the
     * working days until the rest — the order's own value, else the shop's.
     * Null before anything has shipped: then the invoice names the expected
     * period as before.
     *
     * @param array<string, string> $order
     * @param array<string, mixed>  $shop
     *
     * @return array{shippedAt: int, complete: bool, remainderDays: int}|null
     */
    private function shipment(array $order, array $shop): array|null
    {
        $orderId = (int) ($order['id'] ?? 0);

        if ($orderId < 1) {
            return null;
        }

        $first = $this->connection->fetchOne(
            'SELECT MIN(CASE WHEN shippedAt > 0 THEN shippedAt ELSE reportedAt END) FROM tl_consho_delivery WHERE pid = ? AND reportedAt IS NOT NULL',
            [$orderId],
        );

        if (!is_numeric($first) || (int) $first < 1) {
            return null;
        }

        $own = $order['remainderDeliveryDays'] ?? null;

        return [
            'shippedAt' => (int) $first,
            'complete' => $this->balances->of($orderId)?->deliverySettled() ?? true,
            'remainderDays' => is_numeric($own) ? (int) $own : (int) ($shop['remainderDeliveryDays'] ?? 0),
        ];
    }

    /**
     * UUID AND hash. The UUID alone does not notice a
     * replaced logo: Contao keeps it when a file is overwritten under the
     * same name and only updates the hash. A logo set but not (or no longer)
     * in the file system counts as none — there is nothing to freeze.
     *
     * @return array{uuid: string, hash: string}|null
     */
    private function logo(mixed $uuid): array|null
    {
        if (!\is_string($uuid) || 16 !== \strlen($uuid)) {
            return null;
        }

        /*
         * The hash of the CONTENT, the same one InvoiceLogo checks against —
         * not tl_files.hash. The DBAFS skips re-hashing while the
         * modification time is unchanged, and a file replaced by FTP is not
         * in the table until the next sync: a stale table hash frozen here
         * would make the logo "changed" on every regeneration, forever. Both
         * sides have to measure the same thing.
         */
        try {
            $contents = $this->files->read(Uuid::fromString(StringUtil::binToUuid($uuid)));
        } catch (UnableToResolveUuidException|VirtualFilesystemException) {
            return null;
        }

        return ['uuid' => StringUtil::binToUuid($uuid), 'hash' => md5($contents)];
    }

    /**
     * @return array{instructions: string, details: array<string, string>, giroCode: bool, dueDays: int|null, meansCode: string}
     */
    private function payment(int $methodId, string $language, string $fallback): array
    {
        $row = $methodId > 0
            ? $this->connection->fetchAssociative('SELECT type, instructions, jsonData FROM tl_consho_payment_method WHERE id = ?', [$methodId])
            : false;

        if (false === $row) {
            // Without a method the order was paid in full by vouchers at the
            // purchase (WG-5, E1): before anything went out. A method that
            // no longer exists says nothing either way.
            return ['instructions' => '', 'details' => [], 'giroCode' => false, 'dueDays' => null, 'meansCode' => self::meansCode('', []), 'advance' => 0 === $methodId];
        }

        $settings = MethodSettings::decode($row['jsonData'] ?? null);
        $details = [];

        foreach (self::BANK_DETAILS as $key) {
            $value = \is_scalar($settings[$key] ?? null) ? self::plain(trim((string) $settings[$key])) : '';

            if ('' !== $value) {
                $details[$key] = $value;
            }
        }

        return [
            'instructions' => $this->translated('tl_consho_payment_method', $methodId, 'instructions', (string) ($row['instructions'] ?? ''), $language, $fallback),
            'details' => $details,
            // The operator's wish, not the code: DocumentIssuer builds the
            // payload once the invoice number exists (GiroCode).
            'giroCode' => (bool) ($settings['giroCode'] ?? false),
            // The payment term of a purchase on account (E-2, Z1), by name
            // like the bank details. Null for every other method: prepayment
            // is paid before the goods go out and names no term.
            'dueDays' => InvoicePaymentHandler::getType() === (string) ($row['type'] ?? '') ? InvoicePaymentHandler::dueDays($settings) : null,
            'meansCode' => self::meansCode((string) ($row['type'] ?? ''), $details),
            // L-1: paid before the goods go out — every method but a purchase
            // on account and cash on delivery. An invoice issued before
            // shipping then covers an advance payment (UStR 2000 Rz 1524).
            'advance' => !\in_array((string) ($row['type'] ?? ''), [InvoicePaymentHandler::getType(), CashOnDeliveryHandler::getType()], true),
        ];
    }

    /**
     * The payment means of an e-invoice (block R, R12), UNTDID 4461: a SEPA
     * transfer to the account on the invoice, cash for cash on delivery, and
     * "mutually defined" for a transfer without an account on the invoice,
     * for anything a module collects (a card, a wallet) or a voucher.
     *
     * @param array<string, string> $details
     *
     * @internal public for the test only
     */
    public static function meansCode(string $type, array $details): string
    {
        return match ($type) {
            // 30 and 58 both need the account (BR-61, R): without an
            // IBAN nothing on the invoice says where to transfer to.
            PrepaymentHandler::getType(), InvoicePaymentHandler::getType() => '' !== ($details['iban'] ?? '') ? '58' : 'ZZZ',
            CashOnDeliveryHandler::getType() => '10',
            default => 'ZZZ',
        };
    }

    /**
     * A plain-text field as the operator typed it.
     *
     * 🔴 Contao stores text input ENCODED — `( ) & " ' < > # \ =` become
     * entities (C-9 met the same with a password). The invoice template
     * escapes, as it must, so the encoded form printed literally: the reverse
     * charge note came out as "&#40;Binnenmarktregelung&#41;" (C-12),
     * and "Müller & Söhne" as "Müller &amp; Söhne" — on the
     * invoice and in the Giro-Code's beneficiary name alike.
     *
     * Decoded here, where the text leaves Contao and becomes the snapshot:
     * the snapshot holds what the operator meant, and every renderer escapes
     * it once. Not for rich text (the payment instructions) — that is HTML
     * and printed as such.
     */
    private static function plain(string $value): string
    {
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * The same rule as TranslationJoin::coalesce(): the translation if there
     * is a non-empty one, otherwise the main text.
     */
    private function translated(string $table, int $id, string $field, string $main, string $language, string $fallback): string
    {
        if ('' === $language || $language === $fallback || $id < 1) {
            return $main;
        }

        $translated = $this->translations->read($table, $id, $language, $field);

        return '' !== $translated ? $translated : $main;
    }
}
