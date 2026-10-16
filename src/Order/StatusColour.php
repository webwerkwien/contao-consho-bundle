<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Order;

/**
 * The four signals a status can give in the order list.
 *
 * Deliberately not five and not eight. A list is read at a glance, and a glance
 * distinguishes "nothing to do", "in progress", "look at this" and "done" —
 * anything finer needs the record itself.
 *
 * The values name the MEANING, not the colour, because the colour is a
 * rendering decision and the meaning is not. `Attention` stays `Attention` if a
 * later theme paints it something other than red.
 */
enum StatusColour: string
{
    case Open = 'open';
    case Progress = 'progress';
    case Attention = 'attention';
    case Done = 'done';

    /**
     * Contao's own semantic colour variables, not the hex values of its icons.
     *
     * Each of them is redefined for the dark colour scheme in the core
     * stylesheet, so going through the variable is what makes the list legible
     * in both — a fixed #c7241f would stay a dark red on a dark ground.
     */
    public function cssVariable(): string
    {
        return match ($this) {
            self::Open => '--gray',
            self::Progress => '--orange',
            self::Attention => '--red',
            self::Done => '--green',
        };
    }

    public static function forPayment(PaymentState $state): self
    {
        return match ($state) {
            PaymentState::Open => self::Open,
            PaymentState::Partial => self::Progress,
            PaymentState::Paid => self::Done,

            // Money has to go back. That is not an error, but it is the one
            // payment state nobody may overlook in a list.
            PaymentState::Overpaid => self::Attention,

            // Not red: a reversed order is settled, not broken. Red here would
            // cry for attention on every cancelled order forever.
            PaymentState::Refunded => self::Open,

            // Cancelled, never paid: nothing is outstanding either way.
            PaymentState::NothingDue => self::Done,
        };
    }

    public static function forDelivery(DeliveryState $state): self
    {
        return match ($state) {
            DeliveryState::Open => self::Open,
            DeliveryState::Partial => self::Progress,
            DeliveryState::Complete => self::Done,
        };
    }
}
