<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Controller;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentHandlers;
use Webwerkwien\ContaoConshoBundle\Payment\PaymentMethodLookupInterface;
use Webwerkwien\ContaoConshoBundle\Payment\TestPaymentHandler;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * The test provider's own page (spec 6.2; C-11, Z5): "Pay", "Cancel",
 * "Error" and "Send notification twice".
 *
 * It stands where a provider's payment page would, and it behaves like one:
 *
 *   - It knows the order only from the signed session start() put in the
 *     address, and reads no Consho table but the method's secret.
 *   - It notifies over HTTP, like a provider's server — the real notify route,
 *     the real signature check, the real lock. A request from this server to
 *     itself.
 *   - "Twice" sends both notifications AT ONCE. One after the other would
 *     only prove the early return; at once, the second waits on the lock.
 *   - When a notification does not come back 200, the page SAYS so and stays
 *     put. There is no quiet internal fallback: a test that skipped the route
 *     would skip exactly what it is there to test.
 *
 * No request token, like a provider's page: the signed session is the
 * authority, and whoever holds it can at most pay a test order that moves no
 * money. Offered only with CONSHO_TEST_PAYMENT=1 — otherwise a 404.
 */
#[AsController]
class TestPaymentController
{
    private const ACTIONS = ['pay', 'cancel', 'fail', 'twice'];

    public function __construct(
        private readonly PaymentHandlers $handlers,
        private readonly PaymentMethodLookupInterface $methods,
        private readonly HttpClientInterface $client,
        private readonly MoneyFormatter $money,
    ) {
    }

    #[Route(
        path: '/_consho/test-payment',
        name: 'consho_test_payment',
        defaults: ['_scope' => 'frontend', '_token_check' => false],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $input = $request->isMethod('POST') ? $request->request : $request->query;
        $methodId = (int) $input->getString(TestPaymentHandler::PARAM_METHOD);
        $sessionParam = $input->getString(TestPaymentHandler::PARAM_SESSION);
        $signature = $input->getString(TestPaymentHandler::PARAM_SIGNATURE);

        if (!$this->handlers->has(TestPaymentHandler::getType())) {
            throw new NotFoundHttpException('The test provider is switched off.');
        }

        $method = $this->methods->byId($methodId);
        $session = null === $method || TestPaymentHandler::getType() !== $method->type ? null : TestPaymentHandler::decode($sessionParam, $signature, $method->config);

        if (null === $method || null === $session) {
            throw new NotFoundHttpException('Unknown test payment.');
        }

        $secret = TestPaymentHandler::secretOf($method->config);
        $attemptId = (int) ($session['attempt'] ?? 0);
        $return = (string) ($session['return'] ?? '');
        $notify = (string) ($session['notify'] ?? '');
        $action = $request->isMethod('POST') ? $request->request->getString('action') : '';
        $problem = null;

        if (\in_array($action, self::ACTIONS, true)) {
            $result = match ($action) {
                'pay', 'twice' => 'paid',
                'cancel' => 'cancelled',
                'fail' => 'failed',
            };

            // Abo-4b, P100: an order with subscriptions gets a mandate with its payment.
            $paid = ['attempt' => $attemptId, 'event' => 'paid', 'amount' => (string) ($session['amount'] ?? '0')]
                + (1 === (int) ($session['recurring'] ?? 0) ? ['mandate' => TestPaymentHandler::mandateFor($attemptId)] : []);

            $statuses = match ($action) {
                'pay' => $this->notify($notify, $secret, $paid + ['reference' => 'TEST-'.bin2hex(random_bytes(4))], 1),
                'twice' => $this->notify($notify, $secret, $paid + ['reference' => 'TEST-'.bin2hex(random_bytes(4))], 2),
                'fail' => $this->notify($notify, $secret, ['attempt' => $attemptId, 'event' => 'failed'], 1),
                'cancel' => [],
            };

            if ([] === array_filter($statuses, static fn (int|string $status): bool => 200 !== $status)) {
                $query = http_build_query([TestPaymentHandler::PARAM_RESULT => $result, TestPaymentHandler::PARAM_SIGNATURE => TestPaymentHandler::sign(TestPaymentHandler::returnData($attemptId, $result), $secret)]);

                return new RedirectResponse($return.(str_contains($return, '?') ? '&' : '?').$query, Response::HTTP_SEE_OTHER);
            }

            $problem = $statuses;
        }

        return $this->page($request, $methodId, $sessionParam, $signature, $session, $problem);
    }

    /**
     * Sends the notification $times times, all at once, and returns what came
     * back — a status code, or the message of a request that never got one.
     *
     * @param array<string, int|string> $data
     *
     * @return list<int|string>
     */
    private function notify(string $url, string $secret, array $data, int $times): array
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        $responses = [];

        // Started together: HttpClient sends while the loop runs and only
        // waits when a status is asked for.
        for ($i = 0; $i < $times; ++$i) {
            $responses[] = $this->client->request('POST', $url, [
                'body' => $body,
                'headers' => ['Content-Type' => 'application/json', TestPaymentHandler::SIGNATURE_HEADER => TestPaymentHandler::sign($body, $secret)],
                'timeout' => 10,
                'max_redirects' => 0,
            ]);
        }

        $statuses = [];

        foreach ($responses as $response) {
            try {
                $statuses[] = $response->getStatusCode();
            } catch (\Throwable $e) {
                $statuses[] = $e->getMessage();
            }
        }

        return $statuses;
    }

    /**
     * @param array<string, mixed>        $session
     * @param list<int|string>|null       $problem
     */
    private function page(Request $request, int $methodId, string $sessionParam, string $signature, array $session, array|null $problem): Response
    {
        $e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $amount = $this->money->format((string) ($session['amount'] ?? '0'), (string) ($session['currency'] ?? 'EUR'), 'de');
        $hidden = \sprintf(
            '<input type="hidden" name="%s" value="%s"><input type="hidden" name="%s" value="%s"><input type="hidden" name="%s" value="%s">',
            TestPaymentHandler::PARAM_METHOD,
            $methodId,
            TestPaymentHandler::PARAM_SESSION,
            $e($sessionParam),
            TestPaymentHandler::PARAM_SIGNATURE,
            $e($signature),
        );

        $error = null === $problem ? '' : \sprintf(
            '<p role="alert" style="background:#fde8e8;border:1px solid #c0392b;padding:.75em">Die Benachrichtigung kam nicht mit 200 zurück: <strong>%s</strong>. Nichts wurde weitergeleitet — prüfen Sie das Log und ob der Server sich selbst erreicht.</p>',
            $e(implode(', ', $problem)),
        );

        $buttons = '';

        foreach (['pay' => 'Bezahlen', 'cancel' => 'Abbrechen', 'fail' => 'Fehler', 'twice' => 'Bezahlen, Benachrichtigung doppelt senden'] as $action => $label) {
            $buttons .= \sprintf('<p><button type="submit" name="action" value="%s">%s</button></p>', $action, $e($label));
        }

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="de">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex, nofollow">
            <meta name="referrer" content="no-referrer">
            <title>Test-Zahlungsanbieter</title>
            </head>
            <body style="font-family:system-ui,sans-serif;max-width:32rem;margin:2rem auto;padding:0 1rem;line-height:1.5">
            <h1>Test-Zahlungsanbieter</h1>
            <p><strong>Kein echtes Geld.</strong> Diese Seite gibt es nur mit <code>CONSHO_TEST_PAYMENT=1</code>.</p>
            {$error}
            <dl>
            <dt>Bestellung</dt><dd>{$e($session['order'] ?? '')}</dd>
            <dt>Betrag</dt><dd>{$e($amount)}</dd>
            </dl>
            <form method="post" action="{$e($request->getBaseUrl().$request->getPathInfo())}">
            {$hidden}
            {$buttons}
            </form>
            </body>
            </html>
            HTML;

        return new Response($html, null === $problem ? 200 : 502, [
            'Content-Type' => 'text/html; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
