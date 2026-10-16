<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Abo-7, P57/P59: Die Kündigung ist der Eingangsnachweis. Was der Kunde erklärt
 * hat, ist im Backend nicht änderbar; entschieden wird nur über eine
 * außerordentliche Kündigung.
 */
class CancellationDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_cancellation.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testKuendigungenEntstehenNurImFrontendUndBleibenErhalten(): void
    {
        $config = $GLOBALS['TL_DCA']['tl_consho_cancellation']['config'];

        self::assertSame('tl_consho_subscription', $config['ptable']);

        foreach (['closed', 'notCopyable', 'notSortable', 'notDeletable'] as $flag) {
            self::assertTrue($config[$flag] ?? false, $flag);
        }
    }

    public function testDieErklaerungIstNurLesbar(): void
    {
        $fields = $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields'];

        // Read-only, never disabled: a disabled checkbox is saved as unticked.
        foreach (['number', 'receivedAt', 'source', 'kind', 'reason', 'name', 'email', 'requestedAt', 'earliestApplied', 'confirmationSentAt', 'decidedAt'] as $field) {
            self::assertTrue($fields[$field]['eval']['readonly'] ?? false, $field);
            self::assertArrayNotHasKey('disabled', $fields[$field]['eval'], $field);
        }
    }

    public function testNurDieEntscheidungIstEditierbar(): void
    {
        $fields = $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields'];

        foreach (['state', 'effectiveAt', 'rejectReason'] as $field) {
            self::assertFalse($fields[$field]['eval']['readonly'] ?? false, $field);
            self::assertFalse($fields[$field]['eval']['disabled'] ?? false, $field);
        }
    }

    public function testDieNummerIstJeAboEindeutig(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_cancellation');
        $connection->insert('tl_consho_cancellation', ['pid' => 1, 'number' => 'WEB-00084-1-K1']);
        $connection->insert('tl_consho_cancellation', ['pid' => 1, 'number' => 'WEB-00084-1-K2']);

        $this->expectExceptionMessageMatches('/UNIQUE|unique/');

        $connection->insert('tl_consho_cancellation', ['pid' => 1, 'number' => 'WEB-00084-1-K1']);
    }
}
