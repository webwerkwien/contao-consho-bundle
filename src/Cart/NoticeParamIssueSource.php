<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Cart;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationFinding;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssue;
use Webwerkwien\ContaoConshoBundle\Config\ConfigurationIssueSource;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\CancellationController;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\OrderController;
use Webwerkwien\ContaoConshoBundle\Customer\MySubscriptionsFlow;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\WithdrawalController;
use Webwerkwien\ContaoConshoBundle\Payment\ReturnParametersInterface;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Withdrawal\OrderPageWithdrawal;

/**
 * Reports an allow list that swallows the refusal (C-3c).
 *
 * Since C-3c a refused add says so through ?consho_notice=, read back on the
 * product page. Contao's shared cache strips query parameters before the
 * request ever reaches the application — ContaoCache builds
 * StripQueryParametersSubscriber from the env variable QUERY_PARAMS_ALLOW_LIST
 * (manager-bundle, HttpKernel/ContaoCache.php):
 *
 *   - unset (the default): only the DENY_LIST goes, which holds nothing but
 *     tracking parameters (gclid, fbclid, …). consho_notice passes.
 *   - set: everything NOT in it goes, consho_notice included unless one of the
 *     entries matches it.
 *
 * "Matches", not "names it": the entries are regex fragments joined into one
 * case-insensitive pattern, and they are used exactly as written — readEnvCsv()
 * splits on the comma and filters empties, it does not trim. Both details
 * decide whether this finding speaks, so both are mirrored below rather than
 * approximated.
 *
 * ⚠️ The second case fails silently. No exception, no log line — the visitor
 * clicks, gets a 303, lands back on the page and reads nothing. That is the
 * very silence C-3c was built to end, so it is reported rather than hidden.
 *
 * It cannot be fixed from here: the allow list is the operator's decision
 * about their own cache, and the subscriber runs in a kernel this code is not
 * part of. Naming the parameter that has to be added is all this can do.
 */
final class NoticeParamIssueSource implements ConfigurationIssueSource
{
    /**
     * Every parameter the shop reads from the address (review of C-10). The
     * subscriber strips them all alike, and each one fails silently in its
     * own place: the refusal at the buy button, the checkout step (the
     * checkout never leaves step one), the purchase outcome, and the token of
     * the order page — every link from a confirmation mail a 404.
     *
     * The checkout names are literals in CheckoutController; they are
     * repeated here rather than exported, and NoticeParamIssueSourceTest
     * holds the two together by reading that file.
     */
    public const PARAMS = [
        CartNotice::PARAM,
        'consho_step',
        'consho_purchase',
        'consho_ordered',
        OrderController::TOKEN_PARAM,

        // C-11 (review, finding 2): the test provider's page and its signed
        // return. Stripped, the page is a 404 and every return reads as
        // "nothing received yet". Listed although a real shop never uses
        // them: the recommendation "consho_.*" covers them either way, and a
        // test installation is exactly where an allow list meets them.
        TestPaymentHandler::PARAM_METHOD,
        TestPaymentHandler::PARAM_SESSION,
        TestPaymentHandler::PARAM_SIGNATURE,
        TestPaymentHandler::PARAM_RESULT,

        // D-2: the declaration on the order page, and the outcome of the
        // withdrawal form. Stripped, "Vertrag widerrufen" leads back to the
        // order instead of the declaration — a withdrawal function that does
        // not open.
        OrderPageWithdrawal::FORM_PARAM,
        WithdrawalController::NOTICE_PARAM,

        // Abo-7: the confirmation page of "Verträge hier kündigen" and the
        // signed result page. Stripped, the button leads nowhere and the
        // declaration cannot be saved (§ 312k Abs. 2 and 3 BGB).
        CancellationController::FORM_PARAM,
        CancellationForm::RESULT_PARAM,
        CancellationForm::HASH_PARAM,
        CancellationForm::EXPIRES_PARAM,
        // "Meine Abos": the order page of the purchase, the cancellation, the
        // notice — and the pause (Abo pausieren, P101); stripped, the link
        // shows the list again instead of the choice.
        MySubscriptionsFlow::OPEN_PARAM,
        MySubscriptionsFlow::CANCEL_PARAM,
        MySubscriptionsFlow::NOTICE_PARAM,
        MySubscriptionsFlow::PAUSE_PARAM,
    ];

    /**
     * ⚠️ Nullable, and that is not defensive padding: `%env(default::NAME)%`
     * resolves to NULL for a variable that is not set, not to an empty string.
     * A `string` type hint here took the whole back end down with a TypeError
     * on the very first request after the deploy — this service is built for
     * the system messages in the header menu, so every page died, not just the
     * start page. Found on c5 on 2026-09-21; no test saw it, because tests
     * construct the class directly and never build the container.
     */
    /**
     * @param iterable<object>                 $handlers payment modules may name their providers' return
     *                                                     parameters (ReturnParametersInterface, Nachlese K3)
     */
    public function __construct(
        private readonly string|null $allowList,
        #[AutowireIterator('consho.payment_handler')]
        private readonly iterable $handlers = [],
    ) {
    }

    public function issues(): array
    {
        /*
         * Split exactly like ContaoCache::readEnvCsv(): explode on the comma
         * and array_filter, and NOTHING else.
         *
         * ⚠️ Trimming here would be the friendlier reading and the wrong one
         * (found in the C-3c review). Contao does not trim, so the everyday
         * spelling "page, consho_notice" becomes the pattern "^(?: consho_
         * notice)$" — with the space inside — which does not match, and the
         * parameter IS stripped. Reporting nothing there would leave the
         * operator with exactly the silence this finding exists to end.
         */
        $entries = array_filter(explode(',', (string) $this->allowList));

        // No allow list at all is the normal case and the safe one.
        if ([] === $entries) {
            return [];
        }

        /*
         * And matched exactly like the subscriber: its entries are regex
         * fragments, not literals, joined into one case-insensitive pattern.
         * An entry "consho_.*" therefore lets the parameter through and must
         * not be reported, while a plain strcasecmp would have called it
         * missing.
         */
        $params = $this->params();
        $pattern = '/^(?:'.implode(')$|^(?:', $entries).')$/i';
        $matches = @preg_grep($pattern, $params);

        /*
         * An unusable pattern (a stray bracket in the list) breaks the
         * subscriber the same way — and it is predictable what happens then,
         * measured in the C-3c review: preg_grep() returns false, the
         * subscriber runs into "foreach() argument must be of type
         * array|object", strips NOTHING and carries on. So consho_notice
         * actually survives, and the sentence of this finding overstates the
         * case by saying it is stripped.
         *
         * Reported all the same, because that warning is emitted on every
         * cached request that carries a query string and wants finding. The
         * advice — put the parameter in the list — leads to a list that
         * parses, which is the fix either way.
         */
        $missing = false === $matches ? $params : array_values(array_diff($params, $matches));

        if ([] === $missing) {
            return [];
        }

        return [new ConfigurationIssue(
            ConfigurationFinding::NoticeParamStripped,
            ['param' => implode(', ', $missing)],
        )];
    }

    /**
     * Consho's own, then what the payment modules name.
     *
     * @return list<string>
     */
    private function params(): array
    {
        $params = self::PARAMS;

        foreach ($this->handlers as $handler) {
            if ($handler instanceof ReturnParametersInterface) {
                array_push($params, ...$handler::returnParameters());
            }
        }

        return array_values(array_unique($params));
    }
}
