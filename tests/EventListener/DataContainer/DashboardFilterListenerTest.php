<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Dashboard\OpenWorkReader;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DashboardFilterListener;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryStateCalculator;
use Webwerkwien\ContaoConshoBundle\Order\OrderBalanceReader;
use Webwerkwien\ContaoConshoBundle\Order\PaymentStateCalculator;
use Webwerkwien\ContaoConshoBundle\Stock\Availability;
use Webwerkwien\ContaoConshoBundle\Stock\StockIssueSource;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * Dashboard DB4: the link of a tile opens the list with exactly the
 * records counted.
 */
final class DashboardFilterListenerTest extends TestCase
{
    private Connection $connection;

    /** @var list<string> */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);

        foreach (['tl_consho_shop', 'tl_consho_order', 'tl_consho_order_status', 'tl_consho_payment', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_complaint', 'tl_consho_stock', 'tl_consho_product'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Eins']);
        $this->connection->insert('tl_consho_order', ['id' => 4, 'shop' => 1, 'orderNumber' => 'WEB-4', 'grandTotal' => '10.0000', 'currency' => 'EUR', 'language' => 'de']);
        $this->connection->insert('tl_consho_order', ['id' => 5, 'shop' => 1, 'orderNumber' => 'WEB-5', 'grandTotal' => '10.0000', 'currency' => 'EUR', 'language' => 'de', 'paymentSettledAt' => 1]);
        $this->connection->insert('tl_consho_payment', ['pid' => 5, 'amount' => '10.0000']);

        unset($GLOBALS['TL_DCA']);
        $GLOBALS['TL_LANG']['CONSHO']['dashboard'] = ['filtered' => 'Gefiltert vom Dashboard: %s (%d).', 'showAll' => 'Alle anzeigen', 'filters' => ['unpaid' => 'unbezahlt']];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testDieListeZeigtNurDieGezaehltenBestellungen(): void
    {
        $this->listener('/contao?do=consho_order&consho_filter=unpaid&consho_shop=1')->narrow($this->dc('tl_consho_order'));

        self::assertSame([4], $GLOBALS['TL_DCA']['tl_consho_order']['list']['sorting']['root']);
        self::assertCount(1, $this->messages);
        self::assertStringContainsString('Gefiltert vom Dashboard: unbezahlt (1).', $this->messages[0]);
        self::assertStringContainsString('Alle anzeigen', $this->messages[0]);
    }

    public function testNichtsMehrOffenZeigtNichtsStattAllem(): void
    {
        $this->connection->insert('tl_consho_payment', ['pid' => 4, 'amount' => '10.0000']);

        $this->listener('/contao?do=consho_order&consho_filter=unpaid')->narrow($this->dc('tl_consho_order'));

        // An empty root shows ALL records in DC_Table.
        self::assertSame([0], $GLOBALS['TL_DCA']['tl_consho_order']['list']['sorting']['root']);
    }

    /**
     * A case of its own per exit, each on its own — otherwise an empty result
     * only proves that one of them took effect.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function ohneWirkung(): iterable
    {
        yield 'ohne Filter' => ['/contao?do=consho_order', 'tl_consho_order'];
        yield 'beim Bearbeiten' => ['/contao?do=consho_order&consho_filter=unpaid&act=edit&id=5', 'tl_consho_order'];
        yield 'bei einer Aktion' => ['/contao?do=consho_order&consho_filter=unpaid&key=invoicePdf&id=5', 'tl_consho_order'];
        yield 'Filter eines anderen Moduls' => ['/contao?do=consho_order&consho_filter=undecided', 'tl_consho_order'];
        yield 'Filter der Bestellungen bei den Widerrufen' => ['/contao?do=consho_withdrawal&consho_filter=unpaid', 'tl_consho_withdrawal'];
        // From the filtered list into the withdrawals of an order.
        yield 'Kindliste unter der Bestellung' => ['/contao?do=consho_order&table=tl_consho_withdrawal&id=4&consho_filter=unpaid', 'tl_consho_withdrawal'];
    }

    #[DataProvider('ohneWirkung')]
    public function testOhneWirkung(string $uri, string $table): void
    {
        $this->listener($uri)->narrow($this->dc($table));

        self::assertArrayNotHasKey('TL_DCA', $GLOBALS);
        self::assertSame([], $this->messages);
    }

    public function testWiderrufeUndLager(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['id' => 8, 'pid' => 4, 'number' => 1, 'state' => 'received', 'receivedAt' => 1]);

        $this->listener('/contao?do=consho_withdrawal&consho_filter=undecided')->narrow($this->dc('tl_consho_withdrawal'));
        $this->listener('/contao?do=consho_stock&consho_filter=low')->narrow($this->dc('tl_consho_stock'));

        self::assertSame([8], $GLOBALS['TL_DCA']['tl_consho_withdrawal']['list']['sorting']['root']);
        self::assertSame([0], $GLOBALS['TL_DCA']['tl_consho_stock']['list']['sorting']['root'], 'kein Artikel knapp');
    }

    private function dc(string $table): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('__get')->with('table')->willReturn($table);

        return $dc;
    }

    private function listener(string $uri): DashboardFilterListener
    {
        $requests = new RequestStack();
        $requests->push(Request::create($uri));

        $router = $this->createMock(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $name, array $parameters): string => '/contao?'.http_build_query($parameters));

        $messages = &$this->messages;
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): void {
            $messages[] = (string) $args[0];
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($message);

        return new DashboardFilterListener(
            $requests,
            new OpenWorkReader($this->connection, new OrderBalanceReader($this->connection, new PaymentStateCalculator(), new DeliveryStateCalculator())),
            new StockIssueSource($this->connection, new Availability($this->connection)),
            $this->connection,
            $router,
            $framework,
        );
    }
}
