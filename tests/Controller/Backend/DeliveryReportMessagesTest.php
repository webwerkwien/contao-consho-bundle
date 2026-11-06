<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Controller\Backend;

use Contao\CoreBundle\Framework\Adapter;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\DeliveryReportMessages;
use Webwerkwien\ContaoConshoBundle\Order\DeliveryReport;

/**
 * RK-6, A7: a reported replacement delivery names the replacement mail, not
 * the shipping mail.
 */
final class DeliveryReportMessagesTest extends TestCase
{
    /** @var list<array{string, string}> */
    private array $messages = [];

    protected function setUp(): void
    {
        $GLOBALS['TL_LANG'] = [];
        require __DIR__.'/../../../contao/languages/de/tl_consho_delivery.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testErsatzlieferungNenntIhreMail(): void
    {
        DeliveryReportMessages::add($this->adapter(), DeliveryReport::reportedReplacement(true), 'WEB-7');
        DeliveryReportMessages::add($this->adapter(), DeliveryReport::reportedReplacement(false), 'WEB-7');
        DeliveryReportMessages::add($this->adapter(), DeliveryReport::reported(true), 'WEB-7');

        self::assertSame([
            ['addConfirmation', 'Die Ersatzlieferung zur Bestellung WEB-7 ist gemeldet, der Kunde hat die Mail zur Ersatzlieferung bekommen.'],
            ['addInfo', 'Die Ersatzlieferung zur Bestellung WEB-7 ist gemeldet. Die Mail zur Ersatzlieferung ging nicht hinaus — den Grund nennt das Systemlog.'],
            ['addConfirmation', 'Die Lieferung zur Bestellung WEB-7 ist gemeldet, der Kunde hat die Versandmail bekommen.'],
        ], $this->messages);
    }

    /**
     * @return Adapter<\Contao\Message>
     */
    private function adapter(): Adapter
    {
        $messages = &$this->messages;
        $adapter = $this->createMock(Adapter::class);
        $adapter->method('__call')->willReturnCallback(static function (string $name, array $args) use (&$messages): string {
            $messages[] = [$name, (string) $args[0]];

            return '';
        });

        return $adapter;
    }
}
