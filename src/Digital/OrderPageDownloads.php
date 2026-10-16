<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;

/**
 * The entitlements on the order page, and the download behind their button
 * (F-5, F18).
 *
 * The download is a POST action of the order page, not a link of its own
 * (F18): the order token already stands for "this person may see this
 * order", and a separate file link would be a second key with a lifetime of
 * its own — the one TokenPurpose::Download warns about.
 *
 * Streamed from disk (BinaryFileResponse), not read into memory like the
 * invoice PDF: a download may be a gigabyte. Each one is logged with its
 * time only — no IP address, so the log holds no personal data of its own.
 */
class OrderPageDownloads
{
    // Whether an entitlement counts is EntitlementQuery's (Abo-5, P40) — the
    // question modules ask too, so the page and a module cannot disagree.
    private readonly EntitlementQuery $entitlements;

    public function __construct(
        private readonly Connection $connection,
        private readonly DigitalFiles $files,
        private readonly KeyPoolHandler $keys,
        EntitlementQuery|null $entitlements = null,
        // Block licence (LZ-1): what modules delivered. Optional for the
        // tests that build the page without modules.
        private readonly DeliveryHandlers|null $handlers = null,
    ) {
        $this->entitlements = $entitlements ?? new EntitlementQuery($connection);
    }

    /**
     * `keys`: the codes of an active key entitlement (F-6, F20) — on the
     * page, never in a mail.
     *
     * `service` (DL3): the line is a service — nothing to download, the page
     * says "freigeschaltet" or, for a subscription, "läuft"; `performedAt`
     * when the operator reported it as rendered.
     *
     * `items` (block licence, LZ-1): what a module delivered, while active.
     *
     * @return list<array{id: int, name: string, quantity: string, validUntil: int|null, active: bool, resting: bool, file: bool, type: string, keys: list<string>, items: list<array{id: string, label: string, text: string|null, download: bool, action: string|null, actionLabel: string|null}>, service: bool, subscription: bool, performedAt: int|null}>
     */
    public function view(int $orderId, int $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT e.*, i.productName, i.kind, i.performedAt
               FROM tl_consho_entitlement e
               LEFT JOIN tl_consho_order_item i ON i.id = e.orderItem
              WHERE e.pid = ?
              ORDER BY e.id',
            [$orderId],
        );

        // Once per order, not per line: usable() would ask it for each.
        $ended = $this->entitlements->endedLines($orderId);

        return array_map(function (array $row) use ($now, $ended): array {
            $entitlement = Entitlement::fromRow($row);
            $active = $entitlement->isActive($now) && !isset($ended[$entitlement->orderItemId]);

            // Resting is not "no longer valid" (acceptance Abo-5): only the
            // payment is missing. Said only where nothing else ended it.
            $resting = !$active && null !== $entitlement->suspendedAt && !$entitlement->revoked
                && (null === $entitlement->validUntil || $now <= $entitlement->validUntil)
                && !isset($ended[$entitlement->orderItemId]);

            return [
                'id' => $entitlement->id,
                'name' => (string) ($row['productName'] ?? ''),
                'quantity' => $entitlement->quantity,
                'validUntil' => $entitlement->validUntil,
                'active' => $active,
                'resting' => $resting,
                'file' => $active && FileDeliveryHandler::getType() === $entitlement->deliveryType && null !== $this->files->of($entitlement->productId),
                'type' => $entitlement->deliveryType,
                'keys' => $active && KeyPoolHandler::getType() === $entitlement->deliveryType ? $this->keys->codesOf($entitlement->id) : [],
                'items' => $active ? array_map(static fn (DeliveredItem $item): array => [
                    'id' => $item->id,
                    'label' => $item->label,
                    'text' => $item->text,
                    'download' => $item->download,
                    'action' => $item->action,
                    'actionLabel' => $item->actionLabel,
                ], $this->itemsOf($entitlement)) : [],
                'service' => ProductKind::Service === ProductKind::stored((string) ($row['kind'] ?? '')),
                'subscription' => $entitlement->subscription > 0,
                'performedAt' => null === ($row['performedAt'] ?? null) ? null : (int) $row['performedAt'],
            ];
        }, $rows);
    }

    /**
     * The file of an entitlement of THIS order, or null — for an id of
     * another order, a revoked or expired entitlement, or a file that is gone.
     * The caller answers null with a 404, like a wrong token.
     *
     * With `$item`, the download of a module's delivered item (LZ-1, E1):
     * only for an item the module names with a download, under the same
     * checks, with the same private headers.
     */
    public function download(int $orderId, int $entitlementId, int $now, string $item = ''): Response|null
    {
        $entitlement = $this->usableOf($orderId, $entitlementId, $now);

        if (null === $entitlement) {
            return null;
        }

        if ('' !== $item) {
            $handler = $this->presentable($entitlement);
            $offered = null !== $handler && [] !== array_filter($handler->deliveredItems($entitlement), static fn (DeliveredItem $one): bool => $one->id === $item && $one->download);
            $response = $offered ? $handler->download($entitlement, $item) : null;

            if (null === $response) {
                return null;
            }

            $this->connection->insert('tl_consho_download', ['pid' => $entitlement->id, 'tstamp' => $now, 'downloadedAt' => $now]);
            self::keepPrivate($response);

            return $response;
        }

        if (FileDeliveryHandler::getType() !== $entitlement->deliveryType) {
            return null;
        }

        $file = $this->files->of($entitlement->productId);

        if (null === $file) {
            return null;
        }

        $this->connection->insert('tl_consho_download', ['pid' => $entitlement->id, 'tstamp' => $now, 'downloadedAt' => $now]);

        // `public: false` — BinaryFileResponse makes itself public by
        // default and overrode the header given here (acceptance on c5,
        // 2026-09-29: "no-store, public").
        $response = new BinaryFileResponse($file['absolute'], 200, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Referrer-Policy' => 'no-referrer',
        ], false);

        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['name'], self::asciiOf($file['name'])));

        return $response;
    }

    /**
     * Runs the action of a module's delivered item (LZ-1, E2) and returns the
     * notice to show, or null — the caller answers null with a 404. Only for
     * a usable entitlement of THIS order (E4) and an action the module names
     * on that item.
     */
    public function act(int $orderId, int $entitlementId, string $item, string $action, int $now): string|null
    {
        $entitlement = $this->usableOf($orderId, $entitlementId, $now);
        $handler = null === $entitlement ? null : $this->presentable($entitlement);

        if (null === $entitlement || null === $handler || [] === array_filter($handler->deliveredItems($entitlement), static fn (DeliveredItem $one): bool => $one->id === $item && $one->offers($action))) {
            return null;
        }

        return $handler->act($entitlement, $item, $action);
    }

    private function usableOf(int $orderId, int $entitlementId, int $now): Entitlement|null
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_entitlement WHERE id = ? AND pid = ?', [$entitlementId, $orderId]);

        if (false === $row) {
            return null;
        }

        $entitlement = Entitlement::fromRow($row);

        return $this->entitlements->usable($entitlement, $now) ? $entitlement : null;
    }

    private function presentable(Entitlement $entitlement): PresentableDeliveryHandlerInterface|null
    {
        if (null === $this->handlers || !$this->handlers->has($entitlement->deliveryType)) {
            return null;
        }

        $handler = $this->handlers->get($entitlement->deliveryType);

        return $handler instanceof PresentableDeliveryHandlerInterface ? $handler : null;
    }

    /**
     * @return list<DeliveredItem>
     */
    private function itemsOf(Entitlement $entitlement): array
    {
        return $this->presentable($entitlement)?->deliveredItems($entitlement) ?? [];
    }

    /**
     * The order page's rules for whatever a module answers with: not cached,
     * not indexed, no referrer — whatever the module set.
     */
    private static function keepPrivate(Response $response): void
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');
    }

    private static function asciiOf(string $name): string
    {
        $ascii = preg_replace('/[^\x20-\x7E]/', '_', $name) ?? 'download';

        return str_replace(['/', '\\', '%'], '_', $ascii);
    }
}
