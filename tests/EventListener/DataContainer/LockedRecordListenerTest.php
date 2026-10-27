<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\LockedRecordListener;

/**
 * The form of a locked payment (D-1, A2).
 */
final class LockedRecordListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // From the DCA (WG-7), not a copy.
        \Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema::create($this->connection, 'tl_consho_payment');
        $this->connection->insert('tl_consho_payment', ['id' => 1, 'lockedAt' => 100]);
        $this->connection->insert('tl_consho_payment', ['id' => 2]);

        $GLOBALS['TL_DCA']['tl_consho_payment'] = [
            'config' => [],
            'fields' => ['id' => [], 'amount' => ['inputType' => 'text', 'eval' => []], 'receivedAt' => ['inputType' => 'text']],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    /**
     * "Restore version" wrote the old row — with lockedAt NULL — back past the
     * voter. Without a version menu, DC_Table runs neither comparison nor
     * restore.
     */
    public function testEineGesperrteZahlungOeffnetSchreibgeschuetztUndOhneVersionen(): void
    {
        $this->listener()($this->dc(1));

        $dca = $GLOBALS['TL_DCA']['tl_consho_payment'];

        self::assertTrue($dca['config']['hideVersionMenu'] ?? false);
        self::assertTrue($dca['fields']['amount']['eval']['readonly'] ?? false);
        self::assertTrue($dca['fields']['receivedAt']['eval']['readonly'] ?? false);
        self::assertArrayNotHasKey('eval', $dca['fields']['id'], 'Spalten ohne Widget bleiben unberührt');
    }

    public function testEineOffeneZahlungBleibtBearbeitbar(): void
    {
        $this->listener()($this->dc(2));

        self::assertArrayNotHasKey('hideVersionMenu', $GLOBALS['TL_DCA']['tl_consho_payment']['config']);
        self::assertArrayNotHasKey('readonly', $GLOBALS['TL_DCA']['tl_consho_payment']['fields']['amount']['eval']);
    }

    /**
     * CW-6: "Edit multiple" bypassed the write protection. Saving rejects a locked
     * payment; the save that locks it still sees it open (the stored row is
     * what is asked).
     */
    public function testDasSpeichernLehntEineGesperrteZahlungAb(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_payment']['locked'] = 'Gesperrt';

        self::assertSame(['amount' => '5', 'lockedAt' => 200], $this->listener()->refuseLocked(['amount' => '5', 'lockedAt' => 200], $this->dc(2)));
        self::assertSame(['amount' => '5'], $this->listener()->refuseLocked(['amount' => '5'], $this->dc(0)), 'noch kein Datensatz');

        $this->expectExceptionObject(new \RuntimeException('Gesperrt'));

        $this->listener()->refuseLocked(['amount' => '5'], $this->dc(1));
    }

    public function testDieCallbacksSindAngemeldet(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(LockedRecordListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(\Contao\CoreBundle\DependencyInjection\Attribute\AsCallback::class) as $attribute) {
                $targets[] = $attribute->getArguments()['table'].' '.$attribute->getArguments()['target'].' '.$method->getName();
            }
        }

        sort($targets);

        self::assertSame([
            'tl_consho_delivery config.onbeforesubmit refuseLocked',
            'tl_consho_delivery config.onload __invoke',
            'tl_consho_payment config.onbeforesubmit refuseLocked',
            'tl_consho_payment config.onload __invoke',
        ], $targets);
    }

    private function listener(): LockedRecordListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => 'edit']));

        $framework = $this->createStub(ContaoFramework::class);
        $framework->method('getAdapter')->willReturn($this->createStub(Adapter::class));

        return new LockedRecordListener($this->connection, $requests, $framework);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => $id,
            'table' => 'tl_consho_payment',
            default => null,
        });

        return $dc;
    }
}
