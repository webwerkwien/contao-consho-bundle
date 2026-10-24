<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Cancellation\CancellationDecision;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CancellationListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Abo-7, P57: Die Maske einer Kündigung — nur die Entscheidung über eine
 * wartende außerordentliche Kündigung ist bearbeitbar.
 */
final class CancellationListenerTest extends TestCase
{
    private Connection $connection;

    /** @var list<int> */
    private array $applied = [];

    protected function setUp(): void
    {
        require __DIR__.'/../../../contao/dca/tl_consho_cancellation.php';

        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_cancellation');
        $this->connection->insert('tl_consho_cancellation', ['id' => 1, 'pid' => 1, 'number' => 'K1', 'kind' => 'extraordinary', 'state' => 'received']);
        $this->connection->insert('tl_consho_cancellation', ['id' => 2, 'pid' => 1, 'number' => 'K2', 'kind' => 'extraordinary', 'state' => 'accepted', 'decidedAt' => 5]);
        $this->connection->insert('tl_consho_cancellation', ['id' => 3, 'pid' => 1, 'number' => 'K3', 'kind' => 'ordinary', 'state' => 'effective']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testWartendNurDieEntscheidungBearbeitbar(): void
    {
        $this->listener()->onLoad($this->dc(1));

        $fields = $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields'];

        foreach (['state', 'effectiveAt', 'rejectReason'] as $name) {
            self::assertFalse($fields[$name]['eval']['readonly'] ?? false, $name);
        }

        self::assertTrue($fields['name']['eval']['readonly']);
        self::assertSame(['received', 'accepted', 'rejected'], $fields['state']['options'], '„wirksam" ist keine Entscheidung');
        // Abnahme: ein Tag genügt, „31.10.2026" ohne Uhrzeit.
        self::assertSame('date', $fields['effectiveAt']['eval']['rgxp']);
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_cancellation']['config']['hideVersionMenu']);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nichtWartend(): iterable
    {
        yield 'entschieden' => [2];
        yield 'ordentlich' => [3];
    }

    /**
     * @dataProvider nichtWartend
     */
    public function testSonstIstAllesSchreibgeschuetzt(int $id): void
    {
        $this->listener()->onLoad($this->dc($id));

        foreach ($GLOBALS['TL_DCA']['tl_consho_cancellation']['fields'] as $name => $field) {
            if (isset($field['inputType'])) {
                self::assertTrue(($field['eval']['readonly'] ?? false) || ($field['eval']['disabled'] ?? false), $name);
            }
        }

        // The decision is a select: disabled, so it cannot be changed in the browser either.
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_cancellation']['fields']['state']['eval']['disabled'] ?? false);

        self::assertSame('datim', $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields']['effectiveAt']['eval']['rgxp'], 'entschieden: mit Uhrzeit');

        // Kein Wiederherstellen einer unentschiedenen Fassung.
        self::assertTrue($GLOBALS['TL_DCA']['tl_consho_cancellation']['config']['hideVersionMenu']);
    }

    public function testAnnehmenBrauchtEinVertragsende(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener(['effectiveAt' => ''])->checkDecision('accepted', $this->dc(1));
    }

    public function testAblehnenBrauchtEineBegruendung(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener(['rejectReason' => '  '])->checkDecision('rejected', $this->dc(1));
    }

    public function testWirksamIstKeineEntscheidung(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener()->checkDecision('effective', $this->dc(1));
    }

    public function testMitAngabenGehtEsDurch(): void
    {
        $listener = $this->listener(['effectiveAt' => '31.10.2026 00:00', 'rejectReason' => 'Nein.']);

        self::assertSame('accepted', $listener->checkDecision('accepted', $this->dc(1)));
        self::assertSame('rejected', $listener->checkDecision('rejected', $this->dc(1)));
        self::assertSame('received', $listener->checkDecision('received', $this->dc(1)));
    }

    /**
     * Review Abo-7, L2: „Mehrere bearbeiten" hängt die Id an den Feldnamen.
     */
    public function testMehrereBearbeitenMitIdImFeldnamen(): void
    {
        $listener = $this->listener(['effectiveAt_1' => '31.10.2026 00:00']);

        self::assertSame('accepted', $listener->checkDecision('accepted', $this->dc(1)));
    }

    public function testDasVertragsendeNichtVorDemEingang(): void
    {
        $this->connection->update('tl_consho_cancellation', ['receivedAt' => 1_800_000_000], ['id' => 1]);
        $listener = $this->listener();

        self::assertSame(1_800_000_000, $listener->checkEffectiveAt(1_800_000_000, $this->dc(1)), 'ab Eingang: fristlos');
        self::assertNull($listener->checkEffectiveAt(null, $this->dc(1)));

        $this->expectException(\RuntimeException::class);
        $listener->checkEffectiveAt(1_799_999_999, $this->dc(1));
    }

    public function testNachDemSpeichernWirdEntschieden(): void
    {
        $this->listener()->onSubmit($this->dc(1));

        self::assertSame([1], $this->applied);
    }

    public function testInnerhalbEinerTransaktionErstAmEnde(): void
    {
        $listener = $this->listener();
        $this->connection->beginTransaction();
        $listener->onSubmit($this->dc(1));

        self::assertSame([], $this->applied);

        $this->connection->commit();
        $listener->onTerminate();

        self::assertSame([1], $this->applied);
    }

    /**
     * @param array<string, string> $posted
     */
    private function listener(array $posted = []): CancellationListener
    {
        $requests = new RequestStack();
        $requests->push(new Request(['act' => 'edit'], $posted));

        $decision = $this->createMock(CancellationDecision::class);
        $decision->method('apply')->willReturnCallback(function (int $id): bool {
            $this->applied[] = $id;

            return true;
        });

        return new CancellationListener($this->connection, $requests, static fn (): CancellationDecision => $decision);
    }

    /**
     * Nachlese Stufe 2: gebaut erst, wenn eine Entscheidung anzuwenden ist —
     * der Terminate-Listener entsteht bei jeder Anfrage.
     */
    public function testOhneEntscheidungWirdCancellationDecisionNieGebaut(): void
    {
        $listener = new CancellationListener(
            $this->connection,
            new RequestStack(),
            static fn (): CancellationDecision => throw new \LogicException('gebaut'),
        );

        $listener->onTerminate();
        $listener->onLoad();
        $this->addToAssertionCount(1);
    }

    private function dc(int $id): DataContainer
    {
        $dc = $this->createStub(DataContainer::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => match ($key) {
            'id' => $id,
            'table' => 'tl_consho_cancellation',
            default => null,
        });

        return $dc;
    }
}
