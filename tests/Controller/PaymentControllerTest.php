<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Attribute\Route;
use Webwerkwien\ContaoConshoBundle\Controller\PaymentController;

/**
 * The two addresses a provider knows (6.3). Only the notification does
 * without the CSRF check — it comes from the provider's server, which
 * cannot have a request token; the signature takes its place.
 */
class PaymentControllerTest extends TestCase
{
    public function testBenachrichtigungNurPerPostOhneAnfrageTokenMitTokenImPfad(): void
    {
        $route = $this->route('notify');

        self::assertSame('consho_payment_notify', $route->name);
        self::assertSame(['POST'], $route->methods);
        self::assertFalse($route->defaults['_token_check']);
        self::assertSame('[0-9a-f]{64}', $route->requirements['token']);
    }

    public function testRueckkehrNurPerGetUndMitNormalerPruefung(): void
    {
        $route = $this->route('return');

        self::assertSame('consho_payment_return', $route->name);
        self::assertSame(['GET'], $route->methods);
        self::assertArrayNotHasKey('_token_check', $route->defaults);
        self::assertSame('[0-9a-f]{64}', $route->requirements['token']);
    }

    private function route(string $method): Route
    {
        return (new \ReflectionMethod(PaymentController::class, $method))->getAttributes(Route::class)[0]->newInstance();
    }
}
