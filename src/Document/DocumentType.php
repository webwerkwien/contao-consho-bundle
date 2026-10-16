<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

/**
 * The four document types a DACH shop needs from day one.
 *
 * Each draws from its OWN number range: an invoice sequence must be unbroken
 * for the tax office, and mixing delivery notes into it would tear holes in a
 * sequence that is not allowed to have any.
 */
enum DocumentType: string
{
    case Invoice = 'invoice';
    case DeliveryNote = 'deliveryNote';
    case CreditNote = 'creditNote';
    case Cancellation = 'cancellation';

    /**
     * The number range type this document draws from — the same strings the
     * `type` column of tl_consho_number_range accepts.
     */
    public function numberRange(): string
    {
        return $this->value;
    }

    /**
     * Whether this type refers to an earlier document. A credit note and a
     * cancellation are meaningless without the invoice they correct.
     */
    public function requiresRelatedDocument(): bool
    {
        return match ($this) {
            self::CreditNote, self::Cancellation => true,
            default => false,
        };
    }
}
