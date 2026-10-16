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
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationForm;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource;
use Webwerkwien\ContaoConshoBundle\Shop\ShopResolver;

/**
 * "Verträge hier kündigen" (Abo-7; A7, § 312k BGB, P58, P61).
 *
 * The button → the confirmation page (a GET, `consho_cancel=1`) → "jetzt
 * kündigen" (POST) → the result page (303 to a signed address, so a reload
 * does not declare it twice; CancellationForm). Open to anyone: § 312k wants
 * the buttons "ständig verfügbar sowie unmittelbar und leicht zugänglich",
 * which a login would not be.
 *
 * ── Identified by subscription number AND e-mail ─────────────────────────
 *
 * Like "Vertrag widerrufen" (W2): both have to match a subscription of THIS
 * shop — the address of any of its orders, the purchase or a follow-up — or
 * the answer is "nicht gefunden", the same sentence either way. The
 * confirmation goes to the subscription's address, never to one typed here.
 *
 * ── Limited per IP ───────────────────────────────────────────────────────
 *
 * 5 attempts in 15 minutes (`consho.rate_limit.cancellation_form`), every
 * confirming POST counted, found or not.
 */
#[AsContentElement(type: 'consho_cancellation', category: 'consho')]
class CancellationController extends AbstractContentElementController
{
    public const FORM_PARAM = 'consho_cancel';

    public function __construct(
        private readonly ShopResolver $shops,
        private readonly Connection $connection,
        private readonly CancellationForm $form,
        #[Autowire(service: 'consho.rate_limit.cancellation_form')]
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

        $input = ['subscriptionNumber' => '', 'email' => '', ...CancellationForm::read(new Request())];
        $error = null;
        $until = '';
        $showForm = '1' === $request->query->getString(self::FORM_PARAM);

        if ($request->isMethod('POST') && 'confirm' === $request->request->getString('consho_cancellation_action')) {
            $input = [
                'subscriptionNumber' => trim($request->request->getString('consho_cancellation_subscriptionNumber')),
                'email' => trim($request->request->getString('consho_cancellation_email')),
                ...CancellationForm::read($request),
            ];

            [$error, $until] = $this->declare((int) $shop->id, $input, $request, (string) $page->language);
            $showForm = true;
        }

        $template->set('result', $this->form->shown($request, (string) $page->language));
        $template->set('expired', $this->form->expired($request));
        $template->set('form', $showForm);
        $template->set('identify', true);
        $template->set('input', $input);
        $template->set('error', $error);
        $template->set('until', $until);

        $response = $template->getResponse();
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * The error key and, for `alreadyEnding`, the date — or never returns: a
     * recorded cancellation redirects.
     *
     * @param array{subscriptionNumber: string, email: string, kind: string, reason: string, name: string, date: string} $input
     *
     * @return array{0: string, 1: string}
     */
    private function declare(int $shopId, array $input, Request $request, string $language): array
    {
        if (!$this->limiter->create('ip-'.($request->getClientIp() ?? 'unknown'))->consume()->isAccepted()) {
            return ['tooMany', ''];
        }

        if ('' === $input['subscriptionNumber'] || '' === $input['email']) {
            return ['missing', ''];
        }

        $subscriptionId = $this->connection->fetchOne(
            'SELECT s.id FROM tl_consho_subscription s
              WHERE s.shop = ? AND s.subscriptionNumber = ?
                AND EXISTS (SELECT 1 FROM tl_consho_order o
                             WHERE (o.id = s.firstOrder OR o.subscription = s.id) AND LOWER(o.email) = LOWER(?))',
            [$shopId, $input['subscriptionNumber'], $input['email']],
        );

        if (false === $subscriptionId) {
            return ['notFound', ''];
        }

        $now = time();
        [$error, $result] = $this->form->declare($input, (int) $subscriptionId, CancellationSource::Form, $now);

        if (null === $error && null !== $result?->id) {
            throw new RedirectResponseException($this->form->resultUrl($request, $result->id, $now), Response::HTTP_SEE_OTHER);
        }

        $until = null === $result?->effectiveAt ? '' : CancellationForm::moment($result->effectiveAt, $language);

        return [$error ?? 'notFound', $until];
    }
}
