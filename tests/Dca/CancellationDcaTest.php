<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Abo-7, P57/P59: the cancellation is the proof of receipt. What the customer
 * declared cannot be changed in the backend; the only decision taken there is
 * about an extraordinary cancellation.
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

    /**
     * Abo-9: every source the recorder writes is an option of the field, and
     * has a label in both languages — otherwise the mask shows "unknown
     * option".
     */
    public function testJedeQuelleIstEineOptionMitBezeichnung(): void
    {
        $values = array_map(static fn (\Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource $source): string => $source->value, \Webwerkwien\ContaoConshoBundle\Cancellation\CancellationSource::cases());

        self::assertContains('operator', $values);
        self::assertSame($values, $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields']['source']['options']);

        foreach (['de', 'en'] as $language) {
            require __DIR__.'/../../contao/languages/'.$language.'/tl_consho_cancellation.php';
            self::assertSame($values, array_keys($GLOBALS['TL_LANG']['tl_consho_cancellation']['sources']), $language);
        }
    }

    public function testDieErklaerungIstNurLesbar(): void
    {
        $fields = $GLOBALS['TL_DCA']['tl_consho_cancellation']['fields'];

        foreach (['number', 'receivedAt', 'reason', 'name', 'email', 'requestedAt', 'confirmationSentAt', 'decidedAt'] as $field) {
            self::assertTrue($fields[$field]['eval']['readonly'] ?? false, $field);
        }

        // Disabled, not readonly: a browser ignores readonly on a select or checkbox.
        foreach (['source', 'kind', 'earliestApplied'] as $field) {
            self::assertTrue($fields[$field]['eval']['disabled'] ?? false, $field);
            self::assertArrayNotHasKey('readonly', $fields[$field]['eval'], $field);
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
