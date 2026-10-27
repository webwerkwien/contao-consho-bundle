<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

use Brick\Math\BigDecimal;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\Versions;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Document\DeferredArchive;
use Webwerkwien\ContaoConshoBundle\Document\DeliveryNoteIssuer;
use Webwerkwien\ContaoConshoBundle\Document\DocumentArchiverInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentIssuerInterface;
use Webwerkwien\ContaoConshoBundle\Document\DocumentTrigger;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * "Als versandt melden" (D-1, spec D 5.4).
 *
 * An explicit action, not the save of the delivery: its items are child
 * records and exist only after the delivery is stored. Reporting is the
 * moment the parcel is final — from then on it counts in the balance, is
 * locked (A2), and the customer is told what is in it.
 *
 *  1. under lockOrder(): refused if already reported, if it carries nothing,
 *     or if an item exceeds what is still open
 *  2. reportedAt and lockedAt
 *  3. the invoice, if the payment method issues it on shipment (Z2) — with
 *     the FIRST reported delivery, over the whole order
 *  4. the automatic status change
 *  5. the delivery note of this parcel, where the shop issues them (dev#2)
 *  6. after the commit: archive, and the mail "Versendet" with this parcel's
 *     items and the invoice if it was issued now (Z4) — not the delivery
 *     note, which travels in the parcel
 */
class DeliveryReported
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentChangeRepositoryInterface $orders,
        private readonly OrderBalanceReaderInterface $balances,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly DocumentIssuerInterface $documents,
        private readonly DocumentArchiverInterface $archiver,
        private readonly OrderStatusAdvancer $statuses,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        private readonly MoneyFormatter $money,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger = new NullLogger(),
        // A version of the delivery, as the order gets one (Block D);
        // optional for tests that do not look at it.
        private readonly ContaoFramework|null $framework = null,
        private readonly UrlGeneratorInterface|null $router = null,
        // dev#2: the parcel's delivery note, archived when nothing can roll it
        // back. Optional for tests that do not look at it.
        private readonly DeliveryNoteIssuer|null $deliveryNotes = null,
        private readonly DeferredArchive|null $archive = null,
    ) {
    }

    /**
     * @param bool $mail false for the electronic release (F-4): it rides on
     *                   a mail of its own, and "Versendet" would name a parcel
     *                   that does not exist
     */
    public function report(int $deliveryId, int $now, bool $mail = true): DeliveryReport
    {
        $invoiceId = null;
        $deliveryNoteId = null;

        $this->connection->beginTransaction();

        try {
            $orderId = (int) $this->connection->fetchOne('SELECT pid FROM tl_consho_delivery WHERE id = ?', [$deliveryId]);
            $order = $orderId > 0 ? $this->orders->lockOrder($orderId) : null;

            // Read under the lock: two reports of the same delivery wait here.
            $delivery = null === $order ? false : $this->connection->fetchAssociative('SELECT * FROM tl_consho_delivery WHERE id = ?', [$deliveryId]);

            if (null === $order || false === $delivery) {
                $this->connection->commit();

                return DeliveryReport::unknown();
            }

            if (null !== $delivery['reportedAt']) {
                $this->connection->commit();

                return DeliveryReport::alreadyReported();
            }

            $items = $this->itemsOf($deliveryId);

            if ([] === $items) {
                $this->connection->commit();

                return DeliveryReport::empty();
            }

            $before = $this->balances->of($orderId);
            $tooMuch = [];

            foreach ($items as $itemId => $quantity) {
                $open = $before?->openGoods[$itemId] ?? '0';

                if (BigDecimal::of($quantity)->isGreaterThan($open)) {
                    $tooMuch[] = $itemId;
                }
            }

            if ([] !== $tooMuch) {
                $this->connection->commit();

                return DeliveryReport::tooMuch($this->names($orderId, $tooMuch));
            }

            $versions = $this->versions($orderId, $deliveryId);
            $this->connection->update('tl_consho_delivery', ['reportedAt' => $now, 'lockedAt' => $now, 'tstamp' => $now], ['id' => $deliveryId]);
            $versions?->create();

            if (null === $before || !BigDecimal::of($before->claim)->isZero()) {
                if ($this->issuesOnShipment((int) $order['paymentMethod']) && !$this->documents->hasDocument($orderId, DocumentType::Invoice)) {
                    $invoiceId = $this->documents->issueOnce($orderId, (int) $order['shop'], DocumentType::Invoice);
                }
            }

            $this->statuses->advance($orderId, OrderEvent::DeliveryReported, $now);
            $after = $this->balances->of($orderId);

            // dev#2: with what is still open after THIS parcel, the same
            // balance that tells the mail whether the order is complete.
            $deliveryNoteId = $this->deliveryNotes?->issue($deliveryId, $after->openGoods ?? []);

            $this->connection->commit();
        } catch (\Throwable $e) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $e;
        }

        /*
         * The PDF only once nothing can roll the invoice back (Abo-4). Called inside a caller's transaction — the electronic release,
         * a subscription's billing — the commit above only released a
         * savepoint: the invoice number is still locked, and a later rollback
         * would free it while the rendered file stays behind, so the next
         * invoice with that number could not be archived. The caller archives
         * after ITS commit, from the report.
         */
        $nested = $this->connection->getTransactionNestingLevel() > 0;

        if (null !== $invoiceId && !$nested) {
            $this->archiver->archive($invoiceId);
        }

        // Not handed to the caller like the invoice: the callers that report
        // inside their own transaction carry nothing physical, so they get no
        // note — and should one ever do, DeferredArchive waits for its commit.
        if (null !== $deliveryNoteId) {
            $this->archive?->after($deliveryNoteId);
        }

        if (!$mail) {
            return DeliveryReport::reported(false, $nested ? $invoiceId : null);
        }

        $viewUrl = $this->links->forMail($order);

        if (null === $viewUrl) {
            $this->logger->warning(\sprintf('The "shipped" mail of order %s was not sent: the shop has no order page.', $order['orderNumber'] ?? $orderId));

            return DeliveryReport::reported(false, $nested ? $invoiceId : null);
        }

        $sent = $this->mail->shipped($orderId, [
            'carrier' => (string) $delivery['carrier'],
            'trackingNumber' => (string) $delivery['trackingNumber'],
            'items' => $this->mailItems($orderId, $items, (string) ($order['language'] ?? '')),
            'complete' => null !== $after && $after->deliverySettled(),
        ], $invoiceId, $viewUrl);

        // Every "reported" hands a nested caller its invoice — whichever branch
        // it leaves by (Abo-4).
        return DeliveryReport::reported($sent, $nested ? $invoiceId : null);
    }

    /**
     * Initialized before the report is written, created after — like
     * ContaoOrderStatusWriter: named "Consho", since the electronic release
     * (F-4) has no back end user, and the edit link set by hand, since in a
     * cron there is no request to derive it from.
     */
    private function versions(int $orderId, int $deliveryId): Versions|null
    {
        if (null === $this->framework) {
            return null;
        }

        $this->framework->initialize();

        $versions = $this->framework->createInstance(Versions::class, ['tl_consho_delivery', $deliveryId]);
        $versions->setUsername(ContaoOrderStatusWriter::USERNAME);
        $versions->setUserId(0);

        if (null !== $this->router) {
            $versions->setEditUrl($this->router->generate('contao_backend', ['do' => 'consho_order', 'table' => 'tl_consho_delivery', 'act' => 'edit', 'id' => $deliveryId, 'pid' => $orderId]));
        }

        $versions->initialize();

        return $versions;
    }

    /**
     * @return array<int, string> order item id => quantity, positive only
     */
    private function itemsOf(int $deliveryId): array
    {
        $items = [];

        foreach ($this->connection->fetchAllAssociative('SELECT orderItem, quantity FROM tl_consho_delivery_item WHERE pid = ?', [$deliveryId]) as $row) {
            if (BigDecimal::of((string) $row['quantity'])->isPositive()) {
                $item = (int) $row['orderItem'];
                $items[$item] = (string) BigDecimal::of($items[$item] ?? '0')->plus((string) $row['quantity']);
            }
        }

        return $items;
    }

    /**
     * @param list<int> $itemIds
     *
     * @return list<string>
     */
    private function names(int $orderId, array $itemIds): array
    {
        $names = $this->connection->fetchAllKeyValue('SELECT id, productName FROM tl_consho_order_item WHERE pid = ?', [$orderId]);

        return array_map(static fn (int $id): string => (string) ($names[$id] ?? '#'.$id), $itemIds);
    }

    /**
     * @param array<int, string> $items
     *
     * @return list<array{quantity: string, name: string}>
     */
    private function mailItems(int $orderId, array $items, string $language): array
    {
        $names = $this->connection->fetchAllKeyValue('SELECT id, productName FROM tl_consho_order_item WHERE pid = ?', [$orderId]);
        $lines = [];

        foreach ($items as $itemId => $quantity) {
            $lines[] = ['quantity' => $this->money->quantity($quantity, $language), 'name' => (string) ($names[$itemId] ?? '')];
        }

        return $lines;
    }

    private function issuesOnShipment(int $methodId): bool
    {
        $method = $this->methods->byId($methodId);

        if (null === $method || !$this->handlers->has($method->type)) {
            return false;
        }

        return DocumentTrigger::OnShipment === $this->handlers->get($method->type)->documentTrigger($method->config);
    }
}
