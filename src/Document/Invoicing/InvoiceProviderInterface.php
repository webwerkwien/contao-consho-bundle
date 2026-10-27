<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Invoicing;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Webwerkwien\ContaoConshoBundle\Document\Handoff\IssuedDocument;

/**
 * A module that takes over a shop's invoices (block R, R15–R21) — an
 * accounting service such as sevDesk or Lexware Office.
 *
 * A shop chooses who issues its invoices, cancellations and credit notes:
 * Consho, one of these modules, or nobody ("off", the operator invoices
 * elsewhere). Whatever it chooses, Consho keeps the invoice data — every
 * trigger still writes the document row with its frozen snapshot, so the
 * payment term, the OSS return, withdrawals and the address lock work the
 * same in every mode. What differs is who draws the number, renders the PDF
 * and sends it.
 *
 * A module answers two questions per shop, separately:
 *
 *  - issuesItself(): true, and the module issues the document from the
 *    snapshot it is given (issue()) and numbers it in its own system; Consho
 *    draws no number. False, and the shop's invoices stay Consho's, numbered
 *    from Consho's ranges, and the module receives each one after it was
 *    issued (handOver()) to pass it on — Consho then needs its number ranges
 *    and invoice sender as usual.
 *  - sendsItself(): true, and the module sends the invoice to the customer;
 *    Consho attaches nothing. False, and Consho attaches the PDF where it has
 *    one — Consho's own, or the one pdf() returns.
 *
 * The name is stored in tl_consho_shop.invoicing, so it must never change
 * once used.
 *
 * Called after the transaction that wrote the document has committed: a
 * module talks to an outside service, and nothing it does can undo a
 * purchase. A module that throws leaves the document pending; it is offered
 * again by `consho:document:verify --retry`. A cancellation or credit note is
 * offered only once the invoice it refers to has its external id.
 *
 * What the module keeps on its side — a copy of the customer's data, the
 * PDF — is the module's to retain and anonymise; Consho anonymises its own
 * snapshot.
 */
#[AutoconfigureTag('consho.invoice_provider')]
interface InvoiceProviderInterface
{
    /**
     * Stored in tl_consho_shop.invoicing; never "consho" or "off".
     */
    public static function getName(): string;

    /**
     * The label of the module's position in the shop's switch.
     */
    public function label(): string;

    public function issuesItself(int $shopId): bool;

    public function sendsItself(int $shopId): bool;

    /**
     * Only when issuesItself(): issues the invoice, cancellation or credit
     * note in the module's system.
     *
     * Throws when it cannot; the document stays pending and is offered again.
     */
    public function issue(PendingDocument $document): ExternalDocument;

    /**
     * Only when issuesItself(): the module's PDF of a document it issued,
     * null where it has none.
     */
    public function pdf(int $shopId, string $externalId): string|null;

    /**
     * Only when issuesItself() is false: a document Consho issued for the
     * shop — not the delivery note, which carries no amount.
     */
    public function handOver(IssuedDocument $document): void;
}
