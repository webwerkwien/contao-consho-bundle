<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Webwerkwien\ContaoConshoBundle\Payment\NotificationProcessor;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentReturn;

/**
 * The two addresses a payment provider knows (spec 6.3), both per attempt,
 * the attempt's token in the path.
 *
 * The notification is posted by the provider's server, which has no Contao
 * request token and no cookie — hence `_token_check: false`. What stands in
 * for it is the handler's signature check (NotificationProcessor). Nothing
 * the request carries is echoed: the body is empty, the answer a status code.
 *
 * The return is a GET from the buyer's browser. It changes no money and
 * answers with a 303 to the order page.
 */
#[AsController]
class PaymentController
{
    public function __construct(
        private readonly NotificationProcessor $notifications,
        private readonly PaymentReturn $returns,
    ) {
    }

    #[Route(
        path: '/_consho/payment/notify/{token}',
        name: 'consho_payment_notify',
        requirements: ['token' => '[0-9a-f]{64}'],
        defaults: ['_scope' => 'frontend', '_token_check' => false],
        methods: ['POST'],
    )]
    public function notify(string $token, Request $request): Response
    {
        $response = new Response('', $this->notifications->process($token, $request, time()));
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route(
        path: '/_consho/payment/return/{token}',
        name: 'consho_payment_return',
        requirements: ['token' => '[0-9a-f]{64}'],
        defaults: ['_scope' => 'frontend'],
        methods: ['GET'],
    )]
    public function return(string $token, Request $request): Response
    {
        $url = $this->returns->handle($token, $request, time());

        if (null === $url) {
            throw new NotFoundHttpException('Payment attempt not found.');
        }

        $response = new RedirectResponse($url, Response::HTTP_SEE_OTHER);
        $response->headers->set('Cache-Control', 'no-store, private');

        // The Location carries a view token; it must not leave as a referrer
        // from whatever the order page links.
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
