<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

/**
 * Where a price came from.
 *
 * Every price Consho emits carries its source. It serves two purposes at once:
 * the customer-group notice ("as a member of group X you pay …") and the
 * information duty for personalised pricing under § 4 Abs 1 Z 4a FAGG.
 *
 * Adding a value later would mean a rebuild across the whole price
 * calculation, which is why all six exist from the start — even those no code
 * produces yet.
 */
enum PriceSource: string
{
    case List = 'list';
    case Scale = 'scale';
    case CustomerGroup = 'customerGroup';

    /** Reserved for block H (promotions). */
    case Promotion = 'promotion';

    /** Reserved for the AI plugin. Triggers the FAGG notice. */
    case Personalised = 'personalised';

    /** Reserved for A-5, once VAT ID validation exists. */
    case ReverseCharge = 'reverseCharge';

    /*
     * Block Wertgutscheine (WG3): a voucher line whose class the operator
     * marked "nicht steuerbar" — frozen like reverse charge, so the invoice
     * says "nicht steuerbar" instead of "0 %", and no country's tax is
     * claimed for it. Takes precedence over reverse charge: the sale of a
     * multi-purpose voucher is no supply whose tax could pass to anyone.
     */
    case NotTaxable = 'notTaxable';

    /**
     * Whether a customer-facing notice is required for this source.
     */
    public function requiresNotice(): bool
    {
        return match ($this) {
            self::CustomerGroup, self::Personalised => true,
            default => false,
        };
    }
}
