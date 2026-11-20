<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

/**
 * Abo-10, P130: the price offers are a child table of the subscription, read
 * only — they are written by the run, the answer and the biller.
 */
class SubscriptionPriceDcaTest extends TestCase
{
    private const TABLE = 'tl_consho_subscription_price';

    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/'.self::TABLE.'.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testNurLesbarUndKindDesAbos(): void
    {
        $dca = $GLOBALS['TL_DCA'][self::TABLE];

        foreach (['closed', 'notEditable', 'notCopyable', 'notSortable', 'notDeletable'] as $flag) {
            self::assertTrue($dca['config'][$flag] ?? false, $flag);
        }

        self::assertSame('tl_consho_subscription', $dca['config']['ptable']);
        self::assertSame(['show'], array_keys($dca['list']['operations']));
        // P124: one offer per day the price changes at.
        self::assertSame('unique', $dca['config']['sql']['keys']['pid,effectiveAt']);
    }

    public function testJedesFeldUndJederWertHatEineBeschriftungInBeidenSprachen(): void
    {
        $dca = $GLOBALS['TL_DCA'][self::TABLE];
        $fields = array_diff(array_keys($dca['fields']), ['id', 'pid', 'tstamp']);
        $legends = [];
        preg_match_all('/\{(\w+)\}/', $dca['palettes']['default'], $legends);

        foreach (['de', 'en'] as $language) {
            unset($GLOBALS['TL_LANG']);
            require __DIR__.'/../../contao/languages/'.$language.'/'.self::TABLE.'.php';
            $lang = $GLOBALS['TL_LANG'][self::TABLE];

            foreach ([...$fields, ...$legends[1], 'show'] as $key) {
                self::assertArrayHasKey($key, $lang, $language.': '.$key);
            }

            self::assertSame($dca['fields']['state']['options'], array_keys($lang['states']), $language);
            self::assertSame($dca['fields']['answeredBy']['options'], array_keys($lang['answers']), $language);
        }
    }

    public function testZweiAngeboteZumSelbenTagGehenNicht(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, self::TABLE);
        $connection->insert(self::TABLE, ['pid' => 7, 'effectiveAt' => 1800000000]);
        $connection->insert(self::TABLE, ['pid' => 7, 'effectiveAt' => 1810000000]);

        self::assertSame('open', $connection->fetchOne('SELECT state FROM '.self::TABLE.' WHERE id = 1'));

        $this->expectExceptionMessageMatches('/UNIQUE|unique/');

        $connection->insert(self::TABLE, ['pid' => 7, 'effectiveAt' => 1800000000]);
    }

    public function testJedesFeldStehtInDerPalette(): void
    {
        $dca = $GLOBALS['TL_DCA'][self::TABLE];

        foreach (array_diff(array_keys($dca['fields']), ['id', 'pid', 'tstamp']) as $field) {
            self::assertStringContainsString($field, $dca['palettes']['default'], $field);
        }
    }
}
