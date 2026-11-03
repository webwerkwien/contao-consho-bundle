<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\FollowUpsButtonListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * "Nachträge" in every row at the same place: hidden, it shifted the icons
 * after it. Greyed without events, red while one waits, plain once settled.
 */
final class FollowUpsButtonListenerTest extends TestCase
{
    private const ICON = 'bundles/contaoconsho/icons/follow-ups.svg';

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_provider_event');
    }

    public function testOhneMeldungGrauUndGesperrtAberDa(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_order']['followUpsNone'] = 'Keine Nachträge';
        $operation = $this->operation(1);
        (new FollowUpsButtonListener($this->connection))($operation);
        unset($GLOBALS['TL_LANG']['tl_consho_order']['followUpsNone']);

        self::assertSame('bundles/contaoconsho/icons/follow-ups--disabled.svg', $operation['icon']);
        self::assertSame('Keine Nachträge', $operation['title'], 'gesperrt, aber mit Hover-Text');
        self::assertStringContainsString('data-contao--tooltips-target="tooltip"', (string) $operation['iconAttributes']);
        self::assertFalse(isset($operation['href']), 'kein Link');
        self::assertNull($operation->getHtml(), 'gezeichnet, nicht ausgeblendet');
    }

    public function testOffenRotErledigtNormal(): void
    {
        $this->event(1, 'refund', 're_1', 'open');
        $this->event(2, 'chargeback', 'chb_2', 'open');
        $this->event(3, 'refund', 're_3', 'recorded');
        $this->event(3, 'chargeback', 'chb_3', 'settled');
        // Reversed by the bank: nothing left to do.
        $this->event(4, 'chargeback', 'chb_4', 'open', 1_800_000_000);

        $listener = new FollowUpsButtonListener($this->connection);
        $icons = [];

        foreach ([1, 2, 3, 4] as $orderId) {
            $operation = $this->operation($orderId);
            $listener($operation);
            $icons[$orderId] = [$operation['icon'], $operation['href'] ?? null];
        }

        self::assertSame([
            1 => [FollowUpsButtonListener::ICON_OPEN, 'key=followUps'],
            2 => [FollowUpsButtonListener::ICON_OPEN, 'key=followUps'],
            3 => [self::ICON, 'key=followUps'],
            4 => [self::ICON, 'key=followUps'],
        ], $icons);
    }

    public function testDieSymboleGibtEsUndDieDcaNenntSie(): void
    {
        foreach (['', '--dark', '--disabled', '--disabled--dark', '--open'] as $variant) {
            self::assertFileExists(__DIR__.'/../../../public/icons/follow-ups'.$variant.'.svg');
        }

        $dca = file_get_contents(__DIR__.'/../../../contao/dca/tl_consho_order.php') ?: '';
        self::assertStringContainsString("'followUps' => ['href' => 'key=followUps', 'icon' => '".self::ICON."'", $dca);
    }

    private function event(int $orderId, string $kind, string $reference, string $state, int $reversedAt = 0): void
    {
        $this->connection->insert('tl_consho_provider_event', ['pid' => $orderId, 'kind' => $kind, 'reference' => $reference, 'amount' => '1', 'state' => $state, 'reversedAt' => $reversedAt]);
    }

    private function operation(int $orderId): DataContainerOperation
    {
        return new DataContainerOperation('followUps', ['href' => 'key=followUps', 'icon' => self::ICON, 'label' => ['Nachträge', '%s']], ['id' => $orderId], $this->createStub(DataContainer::class));
    }
}
