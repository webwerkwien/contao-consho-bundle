<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webwerkwien\ContaoConshoBundle\Document\DocumentType;

/**
 * Has an issuing module issue the documents Consho wrote without a number
 * (block R, R18), after the transaction that wrote them has committed.
 *
 * ── States ────────────────────────────────────────────────────────────────
 *
 * pending → submitting → issued, or → failed. "submitting" is claimed with a
 * conditional UPDATE before the module is called, so the archiver after a
 * purchase and `consho:document:verify --retry` running at the same moment
 * cannot both have the module issue the same document — a second invoice in
 * the accounting system is worse than a late one. A process that dies while
 * submitting leaves the row there; the findings name it, and the operator
 * checks the module before setting it back.
 *
 * Nothing here undoes a purchase: every failure is logged and recorded.
 *
 * ── Order ─────────────────────────────────────────────────────────────────
 *
 * A cancellation or credit note waits until its invoice has its external id —
 * a module cannot reverse a document it has never seen. The retry takes the
 * documents by id, so an invoice comes before what refers to it.
 *
 * ── After the module issued ───────────────────────────────────────────────
 *
 * Its number is stored as the document number, so mails, the order page and
 * the tax report name it. Should it collide with a number already stored for
 * the shop and type, the external id is kept and the row marked failed: the
 * module has the document, and offering it again would issue it twice. That
 * case needs a person.
 */
class ExternalInvoicing
{
    public const PENDING = 'pending';

    public const SUBMITTING = 'submitting';

    public const ISSUED = 'issued';

    public const FAILED = 'failed';

    public function __construct(
        private readonly Connection $connection,
        private readonly InvoiceProviders $providers,
        #[Autowire(service: 'monolog.logger.contao.error')]
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return bool whether the module issued the document now and the shop
     *              recorded it; false on a number clash, where the module has
     *              the document but the shop does not
     */
    public function submit(int $documentId): bool
    {
        $row = $this->connection->fetchAssociative('SELECT * FROM tl_consho_document WHERE id = ?', [$documentId]);

        if (false === $row || !\in_array($row['externalState'], [self::PENDING, self::FAILED], true) || '' !== (string) $row['externalId']) {
            return false;
        }

        $provider = $this->providers->get((string) $row['issuer']);
        $type = DocumentType::tryFrom((string) $row['type']);

        // Not installed (any more): the document waits for the module.
        if (null === $provider || null === $type) {
            return false;
        }

        $related = '';

        if ((int) $row['relatedDocument'] > 0) {
            $related = (string) $this->connection->fetchOne('SELECT externalId FROM tl_consho_document WHERE id = ?', [(int) $row['relatedDocument']]);

            if ('' === $related) {
                return false;
            }
        }

        $claimed = $this->connection->executeStatement(
            'UPDATE tl_consho_document SET externalState = ? WHERE id = ? AND externalState IN (?, ?)',
            [self::SUBMITTING, $documentId, self::PENDING, self::FAILED],
        );

        if (0 === $claimed) {
            return false;
        }

        $payload = json_decode((string) $row['payload'], true);

        try {
            $external = $provider->issue(new PendingDocument(
                $documentId,
                (int) $row['shop'],
                (int) $row['pid'],
                $type,
                \is_array($payload) ? $payload : [],
                $related,
            ));
        } catch (\Throwable $e) {
            $this->connection->update('tl_consho_document', ['externalState' => self::FAILED], ['id' => $documentId]);
            $this->logger->error(\sprintf('%s could not issue document %d: %s', $provider::class, $documentId, $e->getMessage()), ['exception' => $e]);

            return false;
        }

        try {
            $this->connection->update('tl_consho_document', [
                'documentNumber' => $external->number,
                'issuedAt' => $external->issuedAt,
                'externalId' => $external->externalId,
                'externalState' => self::ISSUED,
                'tstamp' => time(),
            ], ['id' => $documentId]);
        } catch (UniqueConstraintViolationException $e) {
            $this->connection->update('tl_consho_document', ['externalId' => $external->externalId, 'externalState' => self::FAILED], ['id' => $documentId]);
            $this->logger->error(\sprintf('%s issued document %d as %s, a number the shop already has for this type; the module has the document, check it there before anything else.', $provider::class, $documentId, $external->number), ['exception' => $e]);

            return false;
        }

        return true;
    }

    /**
     * Documents still to be issued by a module, oldest first — for the
     * retry. Not those submitting: see the class comment.
     *
     * @param int|null $writtenBefore only documents last written before this
     *                                time — not one whose purchase is still
     *                                running its own attempt and mail
     *
     * @return list<int>
     */
    public function idsToSubmit(int|null $writtenBefore = null): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            "SELECT id FROM tl_consho_document WHERE externalState IN (?, ?) AND externalId = '' AND tstamp < ? ORDER BY id",
            [self::PENDING, self::FAILED, $writtenBefore ?? \PHP_INT_MAX],
        ));
    }
}
