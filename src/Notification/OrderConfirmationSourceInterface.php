<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Notification;

/**
 * Everything the confirmation mails read, and the one thing they write.
 */
interface OrderConfirmationSourceInterface
{
    /**
     * @return array<string, mixed>|null the tl_consho_order row
     */
    public function order(int $orderId): array|null;

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $orderId): array;

    /**
     * The legal texts frozen at the purchase, terms before withdrawal.
     *
     * @return list<array{headline: string, text: string}>
     */
    public function legalTexts(int $orderId): array;

    /**
     * The invoice that stands — the newest one no cancellation points at.
     *
     * @return array{id: int, documentNumber: string, payload: array<string, mixed>}|null
     */
    public function invoice(int $orderId): array|null;

    /**
     * Any document of the order by id — a cancellation included (C-10).
     *
     * @return array{id: int, documentNumber: string, payload: array<string, mixed>}|null
     */
    public function document(int $orderId, int $documentId): array|null;

    /**
     * @return array{operatorName: string, operatorAddress: string, fallbackLanguage: string, withdrawalDays: int, transport: array<string, mixed>|null}|null
     */
    public function shop(int $shopId): array|null;

    public function markSent(int $orderId, int $now): void;

    /**
     * The start point an order belongs to, for a resend from the back end —
     * the purchase knew it, the order does not store it. The shop's start
     * point in the order's language, otherwise its fallback root, otherwise
     * any of the shop's; published ones first.
     */
    public function rootOf(int $shopId, string $language): int|null;

    /**
     * The entitlements of the order (F-7), for the mails that say digital
     * content is ready. `since`: only those released from then on.
     * `service`: the line is a service, unlocked rather than ready (DL3).
     *
     * @return list<array{quantity: string, name: string, service: bool}>
     */
    public function entitlements(int $orderId, int $since = 0): array;

    /**
     * The tax single-purpose vouchers already carried (EZ-4), for an order
     * without an invoice yet — with one, the invoice's snapshot says it.
     *
     * @return list<array{label: string, country: string, rate: string, net: string, tax: string, gross: string}>
     */
    public function prepaid(int $orderId): array;
}
