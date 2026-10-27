<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\DataContainer\DataContainerOperation;
use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogReferences;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\MethodInUseDeleteTitleListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * The grey delete button on „Paket" looked like missing permissions. It now
 * says why: „Das Element ist mit mind. einer Bestellung verknüpft und darf
 * nicht mehr gelöscht werden."
 */
class MethodInUseDeleteTitleListenerTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($this->connection, 'tl_consho_order');
        DcaSchema::create($this->connection, 'tl_consho_subscription');
        $this->connection->insert('tl_consho_order', ['id' => 1, 'shippingMethod' => 2, 'paymentMethod' => 9]);

        $GLOBALS['TL_LANG']['CONSHO']['methodInUse'] = 'Das Element ist mit mind. einer Bestellung verknüpft und darf nicht mehr gelöscht werden.';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testEinGesperrterKnopfEinerVerwendetenArtSagtWarum(): void
    {
        $operation = $this->operationFor('tl_consho_shipping_method', 2, disabledByContao: true);

        self::assertSame('Das Element ist mit mind. einer Bestellung verknüpft und darf nicht mehr gelöscht werden.', $operation['title']);

        // Hover shows the TITLE of the icon, not its alt (seen on a real installation).
        self::assertStringContainsString('title="Das Element ist mit mind. einer Bestellung verknüpft', (string) $operation['iconAttributes']);
    }

    /**
     * Disabled for another reason (missing permission): then the sentence is
     * not true, and it must not be there.
     */
    public function testOhneVerwendungBleibtDerTitelContaos(): void
    {
        $operation = $this->operationFor('tl_consho_shipping_method', 5, disabledByContao: true);

        self::assertSame('Löschen ID 5', $operation['title']);
    }

    public function testEinFreierKnopfBleibtUnveraendert(): void
    {
        $operation = $this->operationFor('tl_consho_payment_method', 9, disabledByContao: false);

        self::assertSame('Löschen ID 9', $operation['title']);
    }

    public function testDieZahlungsartFragtIhreEigeneSpalte(): void
    {
        self::assertSame('Löschen ID 2', $this->operationFor('tl_consho_payment_method', 2, disabledByContao: true)['title']);
        self::assertStringContainsString('Bestellung', (string) $this->operationFor('tl_consho_payment_method', 9, disabledByContao: true)['title']);
    }

    private function operationFor(string $table, int $id, bool $disabledByContao): DataContainerOperation
    {
        $GLOBALS['TL_DCA'][$table]['list']['operations']['delete'] = [
            'href' => 'act=delete',
            'icon' => 'delete.svg',
            'label' => ['Löschen', 'Löschen ID %s'],
            // What DefaultOperationsListener puts there: the voter's answer.
            'button_callback' => static function (DataContainerOperation $operation) use ($disabledByContao): void {
                if ($disabledByContao) {
                    $operation->disable();
                }
            },
        ];

        (new MethodInUseDeleteTitleListener(new CatalogReferences($this->connection)))($table);

        $config = $GLOBALS['TL_DCA'][$table]['list']['operations']['delete'];
        $operation = new DataContainerOperation('delete', $config, ['id' => $id], $this->createStub(DataContainer::class));
        ($config['button_callback'])($operation);

        return $operation;
    }
}
