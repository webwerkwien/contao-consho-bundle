<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;
use Webwerkwien\ContaoConshoBundle\Notification\OrderEventMail;
use Webwerkwien\ContaoConshoBundle\Notification\OrderPageLink;

/**
 * Sends a document the module issued only on `consho:document:verify --retry`
 * (block R, R18). Its order's mail went out while the module failed or was
 * missing, without the document; nothing else would ever send it.
 *
 * Only where Consho sends the module's documents: OrderEventMail sends
 * nothing without the attachment, and a module that sends itself does so
 * once it has issued.
 */
class LateDocumentMail
{
    public function __construct(
        private readonly Connection $connection,
        private readonly OrderPageLink $links,
        private readonly OrderEventMail $mail,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether the customer's mail left
     */
    public function send(int $documentId): bool
    {
        $row = $this->connection->fetchAssociative('SELECT pid, type FROM tl_consho_document WHERE id = ?', [$documentId]);
        $type = false === $row ? null : DocumentType::tryFrom((string) $row['type']);

        if (false === $row || null === $type || DocumentType::DeliveryNote === $type) {
            return false;
        }

        $order = $this->connection->fetchAssociative('SELECT * FROM tl_consho_order WHERE id = ?', [(int) $row['pid']]);

        if (false === $order) {
            return false;
        }

        $viewUrl = $this->links->forMail($order);

        if (null === $viewUrl) {
            $this->logger->warning(\sprintf('Document %d of order %s was not sent: the shop has no order page.', $documentId, $order['orderNumber'] ?? $row['pid']));

            return false;
        }

        return $this->mail->documentIssued((int) $row['pid'], $documentId, $type, $viewUrl);
    }
}
