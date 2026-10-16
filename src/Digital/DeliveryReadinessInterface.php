<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Digital;

/**
 * A delivery type that can say why it would not deliver (Block Lizenz, D7) —
 * before an order waits for it.
 *
 * Optional, beside DeliveryHandlerInterface. release() answering false holds
 * an order back, but nobody sees that. A handler that also implements this is
 * asked by DeliveryReadinessIssueSource, and the start page warns.
 *
 * The answers are reason CODES, not sentences, and the findings are the
 * core's: how loud and for whom (ConfigurationFinding) stays decided in one
 * place, a module only says what is missing. The sentence for a code sits in
 * the module's default.php under
 * `$GLOBALS['TL_LANG']['CONSHO']['deliveryReason'][<type>][<code>]` and names
 * the delivery itself — the core puts it into its finding as it is.
 */
interface DeliveryReadinessInterface extends DeliveryHandlerInterface
{
    /**
     * What stops EVERY article of this type: a missing key pair, a setting
     * only the server holds. Asked only while a published article uses the
     * type — a module installed and not yet used is no warning.
     *
     * @return list<string>
     */
    public function typeProblems(): array;

    /**
     * @param list<int> $articleIds published articles delivered this way,
     *                              variants that inherit it included
     *
     * @return array<int, list<string>> article id => reason codes; an article
     *                                  without a problem may be left out, an
     *                                  id that was not asked about is ignored
     */
    public function articleProblems(array $articleIds): array;
}
