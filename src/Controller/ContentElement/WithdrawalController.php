<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller\ContentElement;

use Contao\ContentModel;
use Contao\CoreBundle\Controller\ContentElement\AbstractContentElementController;
use Contao\CoreBundle\DependencyInjection\Attribute\AsContentElement;
use Contao\CoreBundle\Exception\RedirectResponseException;
use Contao\CoreBundle\Twig\FragmentTemplate;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;
use Webwerkwien\ContaoConshoBundle\Withdrawal\OrderPageWithdrawal;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalDeclaration;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalOutcome;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalRecorder;
use Webwerkwien\ContaoConshoBundle\Withdrawal\WithdrawalSource;

/**
 * "Vertrag widerrufen" for a buyer without the mail (D-2, W1, W2, E4, E6).
 *
 * ── Identified by order number AND e-mail ─────────────────────────────────
 *
 * Both have to match an order of THIS shop, or the answer is "nicht
 * gefunden" — the same sentence whether the number does not exist or the
 * address does not fit (W2). The confirmation goes to the address of the
 * order, never to one typed here: otherwise the form sends mail to anyone.
 * The name is recorded as declared, not matched — "Erika Muster" and "E.
 * Muster" are the same buyer.
 *
 * ── Limited per IP ───────────────────────────────────────────────────────
 *
 * 5 attempts in 15 minutes (E4, `consho.rate_limit.withdrawal_form`). Every
 * confirming POST counts, found or not: counting only the misses would tell a
 * guesser which pairs hit.
 *
 * ── No positions ──────────────────────────────────────────────────────────
 *
 * The whole order, or a part named in free text (E6). A list of positions
 * would show anyone who knows a number and an address what was bought.
 *
 * ── Steps ─────────────────────────────────────────────────────────────────
 *
 * "Vertrag widerrufen" (a GET, `consho_withdraw=1`) → the declaration →
 * "Widerruf bestätigen" (POST). A correctable error renders the form again
 * with what was typed; the recorded withdrawal redirects (303) with its
 * number, so a reload does not declare it twice.
 */
#[AsContentElement(type: 'consho_withdrawal', category: 'consho')]
class WithdrawalController extends AbstractContentElementController
{
    public const NOTICE_PARAM = 'consho_withdrawal';

    public const NUMBER_PARAM = 'consho_withdrawal_number';

    public function __construct(
        private readonly ShopResolver $shops,
        private readonly Connection $connection,
        private readonly WithdrawalRecorder $recorder,
        #[Autowire(service: 'consho.rate_limit.withdrawal_form')]
        private readonly RateLimiterFactoryInterface $limiter,
    ) {
    }

    protected function getResponse(FragmentTemplate $template, ContentModel $model, Request $request): Response
    {
        $page = $request->attributes->get('pageModel');
        $shop = $page instanceof PageModel ? $this->shops->resolveForPage($page) : null;

        if (!$page instanceof PageModel || null === $shop) {
            return new Response('');
        }

        $shopId = (int) $shop->id;
        $input = ['orderNumber' => '', 'email' => '', 'name' => '', 'part' => '', 'message' => ''];
        $error = null;
        $form = '1' === $request->query->getString(OrderPageWithdrawal::FORM_PARAM);

        if ($request->isMethod('POST') && 'confirm' === $request->request->getString('consho_withdrawal_action')) {
            foreach (array_keys($input) as $field) {
                $input[$field] = trim($request->request->getString('consho_withdrawal_'.$field));
            }

            $error = $this->declare($shopId, $input, $request);
            $form = true;
        }

        $notice = $request->query->getString(self::NOTICE_PARAM);

        $template->set('done', \in_array($notice, ['done', 'doneUnconfirmed'], true) ? $notice : null);
        $template->set('number', $request->query->getString(self::NUMBER_PARAM));
        $template->set('form', $form);
        $template->set('input', $input);
        $template->set('error', $error);
        $template->set('contact', 'closed' === $error ? (string) $this->connection->fetchOne('SELECT operatorAddress FROM tl_consho_shop WHERE id = ?', [$shopId]) : '');

        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * The error key, or never returns: a recorded withdrawal redirects.
     *
     * @param array{orderNumber: string, email: string, name: string, part: string, message: string} $input
     */
    private function declare(int $shopId, array $input, Request $request): string
    {
        if (!$this->limiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted()) {
            return 'tooMany';
        }

        if ('' === $input['orderNumber'] || '' === $input['email'] || '' === $input['name']) {
            return 'missing';
        }

        $orderId = $this->connection->fetchOne(
            'SELECT id FROM tl_consho_order WHERE shop = ? AND orderNumber = ? AND LOWER(email) = LOWER(?)',
            [$shopId, $input['orderNumber'], $input['email']],
        );

        if (false === $orderId) {
            return 'notFound';
        }

        $result = $this->recorder->record(new WithdrawalDeclaration(
            (int) $orderId,
            WithdrawalSource::Form,
            $input['name'],
            [],
            $input['part'],
            $input['message'],
        ), time());

        return match ($result->outcome) {
            WithdrawalOutcome::Recorded => throw new RedirectResponseException($this->pageAddress($request, [
                self::NOTICE_PARAM => $result->confirmed ? 'done' : 'doneUnconfirmed',
                self::NUMBER_PARAM => $result->number,
            ]), Response::HTTP_SEE_OTHER),
            WithdrawalOutcome::Closed => 'closed',
            WithdrawalOutcome::UnknownOrder, WithdrawalOutcome::InvalidItems => 'notFound',
        };
    }

    /**
     * @param array<string, string> $query
     */
    private function pageAddress(Request $request, array $query): string
    {
        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query);
    }
}
