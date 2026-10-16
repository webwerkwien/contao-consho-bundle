<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\TaxRateDuplicateListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Ein gespeicherter Satz je Klasse, Land und „Gültig ab" (offener Punkt
 * block A vom 2026-10-11).
 */
class TaxRateDuplicateListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_tax_rate');
        $this->connection->insert('tl_consho_tax_rate', ['id' => 1, 'pid' => 3, 'tstamp' => 5, 'country' => 'AT', 'rate' => '20.0000', 'validFrom' => null]);
        $this->connection->insert('tl_consho_tax_rate', ['id' => 2, 'pid' => 3, 'tstamp' => 5, 'country' => 'DE', 'rate' => '19.0000', 'validFrom' => 1_767_222_000]);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']);
    }

    public function testDieCallbacksSindAngemeldet(): void
    {
        $targets = [];

        foreach ((new \ReflectionClass(TaxRateDuplicateListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                $targets[] = $attribute->getArguments()['table'].' '.$attribute->getArguments()['target'].' '.$method->getName();
            }
        }

        self::assertSame([
            'tl_consho_tax_rate config.onbeforesubmit refuseDuplicate',
            'tl_consho_tax_rate config.oncopy draftDuplicateCopy',
        ], $targets);
    }

    public function testEinNeuerSatzOhneBeginnNebenEinemOhneBeginn(): void
    {
        $GLOBALS['TL_LANG']['tl_consho_tax_rate']['errors']['duplicate'] = 'doppelt: %s';
        $this->connection->insert('tl_consho_tax_rate', ['id' => 9, 'pid' => 3, 'tstamp' => 0, 'country' => '']);

        $this->expectExceptionObject(new \RuntimeException('doppelt: AT'));

        $this->listener()->refuseDuplicate(['tstamp' => 10, 'country' => 'AT', 'rate' => '10', 'validFrom' => null], $this->dc(9, ['id' => 9, 'pid' => 3, 'tstamp' => 0, 'country' => '']));
    }

    /**
     * Ein leerer Text ist ein leerer Beginn, nicht der 01.01.1970.
     */
    public function testEinLeererBeginnIstKeinBeginn(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener()->refuseDuplicate(['country' => 'AT', 'validFrom' => ''], $this->dc(9, ['id' => 9, 'pid' => 3, 'tstamp' => 0, 'country' => '']));
    }

    public function testDerselbeBeginnAuchMitDatum(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener()->refuseDuplicate(['tstamp' => 10, 'country' => 'DE', 'validFrom' => 1_767_222_000], $this->dc(9, ['id' => 9, 'pid' => 3, 'tstamp' => 0, 'country' => '']));
    }

    /**
     * Wer den vorhandenen Satz selbst speichert, trifft nur sich selbst.
     */
    public function testDerSatzSelbstIstKeinPaar(): void
    {
        $values = ['tstamp' => 10, 'rate' => '21'];

        self::assertSame($values, $this->listener()->refuseDuplicate($values, $this->dc(1, ['id' => 1, 'pid' => 3, 'tstamp' => 5, 'country' => 'AT', 'validFrom' => null])));
    }

    /**
     * Ein späterer Beginn löst den früheren ab — gewollt, kein Paar. Ebenso
     * ein anderes Land, eine andere Klasse.
     */
    public function testAndererBeginnLandOderKlasse(): void
    {
        $listener = $this->listener();
        $record = ['id' => 9, 'pid' => 3, 'tstamp' => 0, 'country' => ''];

        foreach ([
            ['country' => 'AT', 'validFrom' => 1_767_222_000],
            ['country' => 'DE', 'validFrom' => null],
            ['country' => 'DE', 'validFrom' => ''],
            ['country' => 'CH', 'validFrom' => null],
        ] as $values) {
            self::assertSame($values, $listener->refuseDuplicate($values, $this->dc(9, $record)));
        }

        self::assertSame(['country' => 'AT'], $listener->refuseDuplicate(['country' => 'AT'], $this->dc(9, ['id' => 9, 'pid' => 4, 'tstamp' => 0, 'country' => ''])));
    }

    /**
     * Wird ein Satz nur im Datum geändert, zählt das gespeicherte Land.
     */
    public function testDasGespeicherteZaehltMit(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->listener()->refuseDuplicate(['validFrom' => null], $this->dc(2, ['id' => 2, 'pid' => 3, 'tstamp' => 5, 'country' => 'AT', 'validFrom' => 1_767_222_000]));
    }

    /**
     * Ein Entwurf (ungespeicherte Kopie) ist kein Satz, mit dem man kollidiert.
     */
    public function testEinEntwurfIstKeinPaar(): void
    {
        $this->connection->insert('tl_consho_tax_rate', ['id' => 7, 'pid' => 3, 'tstamp' => 0, 'country' => 'CH', 'validFrom' => null]);
        $values = ['country' => 'CH', 'validFrom' => null];

        self::assertSame($values, $this->listener()->refuseDuplicate($values, $this->dc(9, ['id' => 9, 'pid' => 3, 'tstamp' => 0, 'country' => ''])));
    }

    /**
     * „Mehrere kopieren" stempelt die Kopie mit time() und führt kein
     * onbeforesubmit aus — sie wird zum Entwurf und zählt erst gespeichert.
     */
    public function testEineGestempelteKopieWirdZumEntwurf(): void
    {
        $this->connection->insert('tl_consho_tax_rate', ['id' => 10, 'pid' => 3, 'tstamp' => 99, 'country' => 'AT', 'validFrom' => null]);
        $this->connection->insert('tl_consho_tax_rate', ['id' => 11, 'pid' => 4, 'tstamp' => 99, 'country' => 'AT', 'validFrom' => null]);

        $this->listener()->draftDuplicateCopy(10, $this->dc(1));
        $this->listener()->draftDuplicateCopy('11', $this->dc(1));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT tstamp FROM tl_consho_tax_rate WHERE id = 10'));
        self::assertSame(99, (int) $this->connection->fetchOne('SELECT tstamp FROM tl_consho_tax_rate WHERE id = 11'), 'eine andere Klasse ist kein Paar');
        self::assertSame(5, (int) $this->connection->fetchOne('SELECT tstamp FROM tl_consho_tax_rate WHERE id = 1'), 'das Original bleibt');
    }

    public function testEinEntwurfOderEineFehlendeKopieBleibt(): void
    {
        $this->connection->insert('tl_consho_tax_rate', ['id' => 10, 'pid' => 3, 'tstamp' => 0, 'country' => 'AT', 'validFrom' => null]);

        $this->listener()->draftDuplicateCopy(10, $this->dc(1));
        $this->listener()->draftDuplicateCopy(99, $this->dc(1));

        self::assertSame(0, (int) $this->connection->fetchOne('SELECT tstamp FROM tl_consho_tax_rate WHERE id = 10'));
    }

    private function listener(): TaxRateDuplicateListener
    {
        return new TaxRateDuplicateListener($this->connection);
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private function dc(int $id, array|null $record = null): DataContainer
    {
        $dc = $this->createMock(DataContainer::class);
        $dc->method('getCurrentRecord')->willReturn($record);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? $id : null);

        return $dc;
    }
}
