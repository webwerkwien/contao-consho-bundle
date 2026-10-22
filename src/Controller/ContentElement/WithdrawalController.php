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
 * ── Matched, or stored unassigned (WA, 2026-10-22) ────────────────────────
 *
 * Do order number AND e-mail match an order of THIS shop, the withdrawal
 * belongs to it and is confirmed as on the order page. Do they not, it is
 * stored anyway — without an order, with the number as typed — and
 * confirmed to the address typed here (WithdrawalRecorder::recordUnassigned).
 * The law asks for "Angaben zur Identifizierung des Vertrags" and the means
 * of the confirmation (§ 356a Abs. 2 BGB, § 13a Abs. 3 FAGG), not for a key
 * the shop insists on; until WA a typo meant "nicht gefunden" and nothing
 * stored (W2). The answer is the same either way — no number, no "no longer
 * possible" (review WA, S1) — so the form tells a stranger nothing; what
 * limits the mail to a typed address is the cap per shop and per address in
 * the recorder.
 *
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
 * with what was typed; the recorded withdrawal redirects (303), so a reload
 * does not declare it twice.
 */
#[AsContentElement(type: 'consho_withdrawal', category: 'consho')]
class WithdrawalController extends AbstractContentElementController
{
    public const NOTICE_PARAM = 'consho_withdrawal';

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

            $error = $this->declare($shopId, (string) $page->language, $input, $request);
            $form = true;
        }

        $notice = $request->query->getString(self::NOTICE_PARAM);

        $template->set('done', 'done' === $notice ? $notice : null);
        $template->set('form', $form);
        $template->set('input', $input);
        $template->set('error', $error);

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
    private function declare(int $shopId, string $language, array $input, Request $request): string
    {
        if (!$this->limiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted()) {
            return 'tooMany';
        }

        if ('' === $input['orderNumber'] || '' === $input['email'] || '' === $input['name']) {
            return 'missing';
        }

        // The confirmation may now go to this address: it has to be one.
        // FILTER_VALIDATE_EMAIL takes at most 254 characters, so it fits the
        // column (255); the name has no such check and is cut to it — on MySQL
        // in strict mode it would otherwise be a 500 (review WA, S4).
        if (false === filter_var($input['email'], \FILTER_VALIDATE_EMAIL)) {
            return 'emailInvalid';
        }

        $input['name'] = mb_substr($input['name'], 0, 255);

        $orderId = $this->connection->fetchOne(
            'SELECT id FROM tl_consho_order WHERE shop = ? AND orderNumber = ? AND LOWER(email) = LOWER(?)',
            [$shopId, $input['orderNumber'], $input['email']],
        );

        $result = false === $orderId ? null : $this->recorder->record(new WithdrawalDeclaration(
            (int) $orderId,
            WithdrawalSource::Form,
            $input['name'],
            [],
            $input['part'],
            $input['message'],
        ), time());

        // Whatever the order refuses — no withdrawal right, cancelled before
        // shipping, a follow-up order, released content — is stored as not
        // assigned instead of answered: a "no longer possible" told a
        // stranger that the order exists (review WA, S1; Michael, 2026-10-22).
        // The operator assigns it and rejects it with a reason.
        if (WithdrawalOutcome::Recorded !== $result?->outcome) {
            $result = $this->recorder->recordUnassigned($shopId, $language, $input['orderNumber'], $input['email'], $input['name'], $input['part'], $input['message'], time());
        }

        // Without the number: its shape (WEB-00073-W1 or W-00001) said whether
        // the order was found. It is in the confirmation mail. And one answer
        // whether the mail went out or not: only the unassigned path has a
        // mail limit, so "not confirmed" after a few tries told a miss from a
        // hit (second review WA, B1). A mail that did not go out is in the
        // log and in the back end (confirmationSentAt).
        throw new RedirectResponseException($this->pageAddress($request, [
            self::NOTICE_PARAM => 'done',
        ]), Response::HTTP_SEE_OTHER);
    }

    /**
     * @param array<string, string> $query
     */
    private function pageAddress(Request $request, array $query): string
    {
        return $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query);
    }
}
