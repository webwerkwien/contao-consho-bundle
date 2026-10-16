<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Confirmation;

enum ConfirmationStatus: string
{
    /** Valid and not yet confirmed: the page asks for the click. */
    case Pending = 'pending';

    /** Confirmed — now or before. A second click is not an error. */
    case Confirmed = 'confirmed';

    /** Unknown, expired, superseded, or for another purpose. */
    case Invalid = 'invalid';
}
