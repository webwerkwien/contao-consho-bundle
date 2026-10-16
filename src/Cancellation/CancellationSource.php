<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cancellation;

/**
 * The two ways in: "Verträge hier kündigen", open to anyone with the
 * subscription number and its e-mail address (P58), and "Meine Abos" in the
 * customer account (P60).
 */
enum CancellationSource: string
{
    case Form = 'form';
    case Account = 'account';
}
