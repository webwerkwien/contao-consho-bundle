<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Exception\RedirectResponseException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\PaymentListToMoneyFlowsListener;

class PaymentListToMoneyFlowsListenerTest extends TestCase
{
    public function testDieListeFuehrtZuDenGeldfluessen(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $redirect = $this->redirect($this->listener('/contao?do=consho_order&table=tl_consho_payment&id=91', $connection));

        self::assertSame('/contao?do=consho_order&key=moneyFlows&id=91', $redirect);
    }

    /**
     * "Zurück" from a new payment not saved: the draft goes, as the list
     * itself would have deleted it.
     */
    public function testEinVerlassenerEntwurfWirdEntfernt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('executeStatement')
            ->with('DELETE FROM tl_consho_payment WHERE id = ? AND pid = ? AND tstamp = 0', [66, 91]);

        $this->redirect($this->listener('/contao?do=consho_order&table=tl_consho_payment&id=91&revise=tl_consho_payment.66', $connection));
    }

    public function testEinFremderReviseBleibtUnberuehrt(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        $this->redirect($this->listener('/contao?do=consho_order&table=tl_consho_payment&id=91&revise=tl_consho_order.66', $connection));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notTheList(): iterable
    {
        yield 'editing' => ['/contao?do=consho_order&table=tl_consho_payment&act=edit&id=5', 'GET'];
        yield 'an operation' => ['/contao?do=consho_order&table=tl_consho_payment&key=refundPayment&id=5', 'GET'];
        yield 'a popup' => ['/contao?do=consho_order&table=tl_consho_payment&id=91&popup=1', 'GET'];
        yield 'a post' => ['/contao?do=consho_order&table=tl_consho_payment&id=91', 'POST'];
        yield 'no order' => ['/contao?do=consho_order&table=tl_consho_payment', 'GET'];
    }

    /**
     * @dataProvider notTheList
     */
    public function testNurDieListeEinerBestellung(string $uri, string $method): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('executeStatement');

        // No redirect: returns.
        $this->listener($uri, $connection, $method)();
        $this->addToAssertionCount(1);
    }

    private function listener(string $uri, Connection $connection, string $method = 'GET'): PaymentListToMoneyFlowsListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create($uri, $method));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $query): string => '/contao?'.http_build_query($query));

        return new PaymentListToMoneyFlowsListener($requests, $connection, $router);
    }

    private function redirect(PaymentListToMoneyFlowsListener $listener): string
    {
        try {
            $listener();
        } catch (RedirectResponseException $e) {
            return (string) $e->getResponse()->headers->get('Location');
        }

        self::fail('no redirect');
    }
}
