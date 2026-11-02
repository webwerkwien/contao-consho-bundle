<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * A payment handler that can say why a published method of its type will
 * not work as the operator expects (block Zahlung Mollie) — a test key that
 * takes no money, a licence that keeps the method out of the checkout.
 *
 * Optional, beside PaymentHandlerInterface, like DeliveryReadinessInterface
 * for delivery types. isAvailable() answering false hides the method, but
 * nobody sees why. A handler that also implements this is asked by
 * PaymentReadinessIssueSource, and the start page warns.
 *
 * The answers are reason CODES, not sentences, and the finding is the core's:
 * how loud and for whom (ConfigurationFinding) stays decided in one place, a
 * module only says what is wrong. The sentence for a code sits in the
 * module's default.php under
 * `$GLOBALS['TL_LANG']['CONSHO']['paymentReason'][<type>][<code>]` — the core
 * puts it into its finding as it is, after the method's title.
 */
interface PaymentReadinessInterface extends PaymentHandlerInterface
{
    /**
     * Asked once per published method of this type, with its settings —
     * a module installed and not yet used is no warning.
     *
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    public function methodProblems(array $config): array;
}
