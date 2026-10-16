<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Request;
use Webwerkwien\ContaoConshoBundle\Cart\ShopPageUrls;
use Webwerkwien\ContaoConshoBundle\Controller\ContentElement\OrderController;
use Webwerkwien\ContaoConshoBundle\Notification\OrderConfirmationSourceInterface;
use Webwerkwien\ContaoConshoBundle\Token\TokenGenerator;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;

/**
 * The browser coming back from the provider (spec 7.4; C-11, Z2).
 *
 * Asks the handler what the return says, notes it on the attempt, and sends
 * the buyer to the order page. **Records no money** — whoever calls the
 * address pays nothing (spec 12), and a verified "succeeded" lands in
 * returnState, never in the state that counts.
 *
 * ── A fresh token for the order page, every time ─────────────────────────
 *
 * The return address carries the ATTEMPT's token, not the order page's: the
 * provider stores that address, and an order page token in a provider's logs
 * would be a key to the order in someone else's system. The view token is
 * issued here instead, and does not confirm the e-mail address — a return
 * proves the buyer reached the provider, not that they read their mail.
 *
 * The attempt's token opens the order through this route all the same; that
 * is what a return address is for, and the provider has it either way.
 */
class PaymentReturn
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PaymentAttemptRepositoryInterface $attempts,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly PaymentHandlers $handlers,
        private readonly OrderConfirmationSourceInterface $orders,
        private readonly TokenGenerator $tokens,
        private readonly ShopPageUrls $pageUrls,
        private readonly ContaoFramework $framework,
    ) {
    }

    /**
     * @return string|null the order page to go to, null for an unknown attempt
     */
    public function handle(string $token, Request $request, int $now): string|null
    {
        $attempt = $this->attempts->findByHash(hash('sha256', $token));

        if (null === $attempt) {
            return null;
        }

        $order = $this->orders->order($attempt->orderId);

        if (null === $order) {
            return null;
        }

        // A switched-off handler still sends the buyer to their order; it
        // just has nothing to say about the return.
        if ($this->handlers->has($attempt->methodType)) {
            $returned = $this->handlers->get($attempt->methodType)->handleReturn($request, $attempt, $this->methods->byId($attempt->methodId)->config ?? []);

            if (AttemptState::Pending !== $returned && AttemptState::Open !== $returned) {
                $this->connection->beginTransaction();

                try {
                    // Under the lock: the notification may be writing the
                    // same attempt right now (spec 7.4, step 3).
                    if (null !== $this->attempts->lock($attempt->id)) {
                        $this->attempts->recordReturn($attempt->id, $returned, $now);
                    }

                    $this->connection->commit();
                } catch (\Throwable $e) {
                    $this->connection->rollBack();

                    throw $e;
                }
            }
        }

        $this->framework->initialize();

        $rootId = $this->orders->rootOf((int) $order['shop'], (string) $order['language']);
        $root = null === $rootId ? null : $this->framework->getAdapter(PageModel::class)->findById($rootId);
        $orderPage = $root instanceof PageModel ? $this->pageUrls->orderOfRoot($root) : null;

        if (null === $orderPage) {
            return null;
        }

        return \sprintf('%s?%s', $orderPage, http_build_query([OrderController::TOKEN_PARAM => $this->tokens->issue($attempt->orderId, TokenPurpose::ViewOrder)]));
    }
}
