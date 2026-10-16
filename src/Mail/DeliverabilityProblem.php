<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Mail;

/**
 * What is wrong with a sender's deliverability, as a code rather than a
 * sentence.
 *
 * The report is read by a shop operator in the back end, and Consho is built
 * for the DACH region — so the wording has to be translatable. A checker that
 * returned finished English sentences would either force English on a German
 * operator or drag the translator into a class that has no business knowing
 * about Contao. The sentences live in the language files; the report says which
 * ones apply and, through senderDomain and authenticatedDomain, what to put in
 * them.
 */
enum DeliverabilityProblem: string
{
    case SenderAddressInvalid = 'senderAddressInvalid';
    case SpfMissing = 'spfMissing';
    case DmarcMissing = 'dmarcMissing';
    case AlignmentMismatch = 'alignmentMismatch';
}
