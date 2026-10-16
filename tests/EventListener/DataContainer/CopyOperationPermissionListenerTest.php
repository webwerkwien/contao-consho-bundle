<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\CreateAction;
use Contao\DataContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CopyOperationPermissionListener;

/**
 * Die Rechteprüfung, die die fünf Kindtabellen sich selbst holen.
 *
 * Sie schreiben ihre Kopieren-Operation aus, weil Contaos Standard dort die
 * Zwischenablage wäre — und ein ausgeschriebenes Array bekommt nichts
 * dazu. Ohne diesen Callback würde der Voter erst in `DC_Table::copy()`
 * gefragt, also **nach** dem Klick.
 */
class CopyOperationPermissionListenerTest extends TestCase
{
    public function testDerKnopfBleibtWennAnlegenErlaubtIst(): void
    {
        $operation = self::operation('tl_consho_tax_rate', ['id' => 7, 'pid' => 2]);

        $this->listener(true)($operation);

        self::assertSame('act=copy', $operation['href'], 'Ein erlaubtes Anlegen darf nichts abschalten.');
        self::assertSame('copy.svg', $operation['icon']);
    }

    public function testDerKnopfWirdGrauWennAnlegenVerbotenIst(): void
    {
        $operation = self::operation('tl_consho_price', ['id' => 21, 'pid' => 1]);

        $this->listener(false)($operation);

        // `disable()` nimmt href und route weg und tauscht das Symbol.
        self::assertArrayNotHasKey('href', $operation);
        self::assertSame('copy--disabled.svg', $operation['icon']);
    }

    /**
     * Der Voter bekommt genau das, was Contao für die `act=copy`-Fassung
     * baut: die Zeile, wie sie dasteht.
     *
     * ⚠️ **Nicht** mit `'sorting' => null` überschrieben — das gehört zur
     * Paste-Fassung, und die benutzen diese Tabellen gerade nicht.
     */
    public function testDerVoterBekommtTabelleUndZeile(): void
    {
        $record = ['id' => 4, 'pid' => 2, 'country' => 'DK'];
        $seen = [];

        $security = $this->createMock(Security::class);
        $security
            ->method('isGranted')
            ->willReturnCallback(
                static function (string $attribute, mixed $subject) use (&$seen): bool {
                    $seen = [$attribute, $subject];

                    return true;
                },
            )
        ;

        (new CopyOperationPermissionListener($security))(self::operation('tl_consho_tax_rate', $record));

        self::assertSame(ContaoCorePermissions::DC_PREFIX.'tl_consho_tax_rate', $seen[0]);
        self::assertInstanceOf(CreateAction::class, $seen[1]);
        self::assertSame('tl_consho_tax_rate', $seen[1]->getDataSource());
        self::assertSame($record, $seen[1]->getNew());
    }

    /**
     * Ohne Zeile gibt es nichts zu beurteilen. Hier „nein" zu sagen hieße,
     * einen Knopf über einem fehlenden Datensatz auszugrauen statt über
     * einem verbotenen — und `DC_Table::copy()` fragt ohnehin noch.
     */
    public function testOhneDatensatzWirdDerVoterGarNichtGefragt(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::never())->method('isGranted');

        $operation = self::operation('tl_consho_price', null);

        (new CopyOperationPermissionListener($security))($operation);

        self::assertSame('act=copy', $operation['href']);
    }

    /**
     * Die fünf Einträge sind kein Selbstzweck: Der DCA-Wächtertest hält sie
     * gegen die Masken, dieser hier gegen sich selbst.
     */
    public function testDieTabellenlisteHatKeineDoppelten(): void
    {
        self::assertSame(
            CopyOperationPermissionListener::TABLES,
            array_values(array_unique(CopyOperationPermissionListener::TABLES)),
        );
    }

    private function listener(bool $granted): CopyOperationPermissionListener
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn($granted);

        return new CopyOperationPermissionListener($security);
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function operation(string $table, array|null $record): DataContainerOperation
    {
        // DataContainer ist abstrakt und hat genau zwei abstrakte Methoden;
        // den Konstruktor der Elternkette rufen wir bewusst nicht auf, sonst
        // hinge der Test am Container. Gelesen wird nur `table`.
        $dc = new class($table) extends DataContainer {
            public function __construct(string $table)
            {
                $this->strTable = $table;
            }

            public function getPalette(): string
            {
                return '';
            }

            protected function save($varValue): void
            {
            }
        };

        return new DataContainerOperation(
            'copy',
            ['href' => 'act=copy', 'method' => 'POST', 'icon' => 'copy.svg'],
            $record,
            $dc,
        );
    }
}
