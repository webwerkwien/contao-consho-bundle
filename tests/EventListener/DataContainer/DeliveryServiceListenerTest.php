<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webwerkwien\ContaoConshoBundle\Digital\DigitalRelease;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\DeliveryServiceListener;
use Webwerkwien\ContaoConshoBundle\Order\PaymentChangeRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Order\ServicePerformed;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * "Leistung erbracht" in the delivery list, where everything that fulfils an
 * order is — not at the order's lines.
 */
final class DeliveryServiceListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        require __DIR__.'/../../../contao/languages/de/tl_consho_delivery.php';
        $GLOBALS['TL_CONFIG']['dateFormat'] = 'd.m.Y';

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_order', 'tl_consho_order_item', 'tl_consho_delivery', 'tl_consho_delivery_item', 'tl_consho_entitlement'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->insert('tl_consho_order', ['id' => 4, 'orderNumber' => 'WEB-4', 'grandTotal' => '50']);
        $this->connection->insert('tl_consho_order_item', ['id' => 9, 'pid' => 4, 'productName' => 'Beratung', 'quantity' => '1', 'kind' => 'service']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG'], $GLOBALS['TL_CONFIG']['dateFormat']);
    }

    public function testFreigeschalteteLeistungBietetLeistungErbracht(): void
    {
        $this->release();

        $suffix = $this->listener()->rowSuffix(3);

        self::assertStringContainsString('Beratung: <a href="/contao?key=servicePerformed&amp;id=9', $suffix);
        self::assertStringContainsString('>Leistung erbracht</a>', $suffix);
        self::assertStringContainsString('return confirm(', $suffix);
    }

    public function testErbrachteLeistungNenntDasDatum(): void
    {
        $this->release();
        $this->connection->update('tl_consho_order_item', ['performedAt' => mktime(12, 0, 0, 3, 2, 2099)], ['id' => 9]);

        self::assertSame(' — Beratung: erbracht am 02.03.2099', $this->listener()->rowSuffix(3));
    }

    public function testWiderrufeneLeistungBietetNichts(): void
    {
        $this->release();
        $this->connection->update('tl_consho_entitlement', ['revoked' => 1], ['orderItem' => 9]);

        self::assertSame('', $this->listener()->rowSuffix(3));
    }

    public function testNochNichtFreigeschaltetNenntDasDatum(): void
    {
        self::assertSame(
            ['„Beratung“ wird am 26.11.2099 freigeschaltet. Danach steht hier an der Lieferung „Leistung erbracht“.'],
            $this->listener(mktime(12, 0, 0, 11, 26, 2099))->notes(4),
        );
    }

    public function testFreigeschaltetKeinHinweis(): void
    {
        $this->release();

        self::assertSame([], $this->listener(mktime(12, 0, 0, 11, 26, 2099))->notes(4));
    }

    /**
     * The release as DigitalRelease leaves it: an entitlement and a reported
     * electronic delivery of the line.
     */
    private function release(): void
    {
        $this->connection->insert('tl_consho_entitlement', ['pid' => 4, 'orderItem' => 9]);
        $this->connection->insert('tl_consho_delivery', ['id' => 3, 'pid' => 4, 'reportedAt' => 100]);
        $this->connection->insert('tl_consho_delivery_item', ['pid' => 3, 'orderItem' => 9, 'quantity' => '1']);
    }

    private function listener(int|null $dueAt = null): DeliveryServiceListener
    {
        $release = $this->createStub(DigitalRelease::class);
        $release->method('dueAt')->willReturn($dueAt);

        $tokens = $this->createStub(ContaoCsrfTokenManager::class);
        $tokens->method('getDefaultTokenValue')->willReturn('token');

        $router = $this->createStub(UrlGeneratorInterface::class);
        $router->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/contao?'.http_build_query(array_diff_key($parameters, ['do' => 1, 'table' => 1])));

        return new DeliveryServiceListener(
            $this->connection,
            new ServicePerformed($this->connection, $this->createStub(PaymentChangeRepositoryInterface::class)),
            $release,
            $tokens,
            $router,
            new RequestStack(),
            $this->createStub(ContaoFramework::class),
        );
    }
}
