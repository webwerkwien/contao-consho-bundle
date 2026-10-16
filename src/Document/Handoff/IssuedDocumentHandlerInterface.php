<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document\Handoff;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The seam for accounting modules (block R, R13): every invoice, credit note
 * and cancellation Consho issues, once, after the transaction that issued it
 * has committed — for a module that passes it on to sevDesk, Lexware Office
 * or a tax adviser's mailbox.
 *
 * Consho keeps issuing the documents; a module receives them, it does not
 * replace them. Which shops it serves is the module's own setting.
 *
 * A module that throws stops nothing: the document stands, the system log
 * names the module and the document, and the next module still gets it. A
 * module that must not lose a document keeps its own queue — Consho hands
 * each one over once and does not retry.
 */
#[AutoconfigureTag('consho.issued_document_handler')]
interface IssuedDocumentHandlerInterface
{
    public function handle(IssuedDocument $document): void;
}
