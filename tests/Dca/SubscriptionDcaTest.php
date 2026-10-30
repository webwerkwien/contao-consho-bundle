<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\SubscriptionHistoryBackendController;
use Webwerkwien\ContaoConshoBundle\Controller\Backend\SubscriptionPauseBackendController;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Tests\Subscription\SubscriptionSchema;

/**
 * Abo-3, P20: the subscription is visible in the backend but not editable —
 * editing would be a way around the frozen values (A9).
 */
class SubscriptionDcaTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__.'/../../contao/dca/tl_consho_subscription.php';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG'], $GLOBALS['BE_MOD']);
    }

    public function testDasAboIstNurLesbar(): void
    {
        $dca = $GLOBALS['TL_DCA']['tl_consho_subscription'];

        foreach (['closed', 'notEditable', 'notCopyable', 'notDeletable'] as $flag) {
            self::assertTrue($dca['config'][$flag] ?? false, $flag);
        }

        // Abo pausieren (P101, P111), Abo-7 (P57): the pause page, then
        // cancellations and pauses on one history page, the purchase order
        // before the details (Abo-8, P80, without an href of its own) — still
        // no edit.
        self::assertSame(['pause', 'history', 'order', 'show'], array_keys($dca['list']['operations']));
        self::assertArrayNotHasKey('href', $dca['list']['operations']['order']);
        self::assertSame('key=pauseSubscription', $dca['list']['operations']['pause']['href']);
        self::assertSame('key=subscriptionHistory', $dca['list']['operations']['history']['href']);
        self::assertSame(['tl_consho_cancellation', 'tl_consho_subscription_pause'], $dca['config']['ctable']);
    }

    public function testDieAbonummerIstEindeutig(): void
    {
        self::assertSame('unique', $GLOBALS['TL_DCA']['tl_consho_subscription']['config']['sql']['keys']['subscriptionNumber']);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        SubscriptionSchema::create($connection);
        $connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00083-1']);

        $this->expectExceptionMessageMatches('/UNIQUE|unique/');

        $connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00083-1']);
    }

    public function testNeuesAboStartetInDerMindestlaufzeitAktivUndOhneEnde(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        SubscriptionSchema::create($connection);
        $connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00083-1']);

        $row = $connection->fetchAssociative('SELECT phase, state, endsAt FROM tl_consho_subscription');

        self::assertSame(['phase' => 'minimum', 'state' => 'active', 'endsAt' => null], $row);
    }

    public function testJedesFeldHatEineBeschriftungInBeidenSprachen(): void
    {
        $fields = array_diff(array_keys($GLOBALS['TL_DCA']['tl_consho_subscription']['fields']), ['id', 'tstamp']);
        $legends = [];
        preg_match_all('/\{(\w+)\}/', $GLOBALS['TL_DCA']['tl_consho_subscription']['palettes']['default'], $legends);

        foreach (['de', 'en'] as $language) {
            unset($GLOBALS['TL_LANG']);
            require __DIR__.'/../../contao/languages/'.$language.'/tl_consho_subscription.php';
            require __DIR__.'/../../contao/languages/'.$language.'/modules.php';

            foreach ([...$fields, ...$legends[1], 'show', 'history', 'pause', 'order', 'subscriptionHistory'] as $key) {
                self::assertArrayHasKey($key, $GLOBALS['TL_LANG']['tl_consho_subscription'], $language.': '.$key);
            }

            self::assertArrayHasKey('consho_subscription', $GLOBALS['TL_LANG']['MOD'], $language);
        }
    }

    /**
     * The details showed "year", "indefinite", "content". Every value that can
     * be saved has a label.
     */
    public function testJederGespeicherteWertHatEineBeschriftung(): void
    {
        $values = [
            'termUnits' => ['month', 'year'],
            'renewals' => array_map(static fn (RenewalKind $kind): string => $kind->value, RenewalKind::cases()),
            'kinds' => array_map(static fn (ProductKind $kind): string => $kind->value, ProductKind::cases()),
            'phases' => [$GLOBALS['TL_DCA']['tl_consho_subscription']['fields']['phase']['sql']['default']],
            'states' => [$GLOBALS['TL_DCA']['tl_consho_subscription']['fields']['state']['sql']['default']],
        ];

        foreach (['de', 'en'] as $language) {
            unset($GLOBALS['TL_LANG']);
            require __DIR__.'/../../contao/languages/'.$language.'/tl_consho_subscription.php';

            foreach ($values as $key => $stored) {
                foreach ($stored as $value) {
                    self::assertArrayHasKey($value, $GLOBALS['TL_LANG']['tl_consho_subscription'][$key], $language.': '.$key);
                }
            }
        }

        // The fields point to exactly these lists.
        $fields = $GLOBALS['TL_DCA']['tl_consho_subscription']['fields'];
        foreach (['termUnit' => 'termUnits', 'renewal' => 'renewals', 'kind' => 'kinds', 'phase' => 'phases', 'state' => 'states'] as $field => $key) {
            self::assertArrayHasKey('reference', $fields[$field], $field);
        }
    }

    public function testDasModulStehtDirektNachDenBestellungen(): void
    {
        require __DIR__.'/../../contao/config/config.php';

        $daily = array_keys($GLOBALS['BE_MOD']['consho']);

        self::assertSame(array_search('consho_order', $daily, true) + 1, array_search('consho_subscription', $daily, true));
        self::assertSame(['tl_consho_subscription', 'tl_consho_cancellation', 'tl_consho_subscription_pause'], $GLOBALS['BE_MOD']['consho']['consho_subscription']['tables']);
        self::assertSame([SubscriptionPauseBackendController::class, '__invoke'], $GLOBALS['BE_MOD']['consho']['consho_subscription']['pauseSubscription']);
        self::assertSame([SubscriptionHistoryBackendController::class, '__invoke'], $GLOBALS['BE_MOD']['consho']['consho_subscription']['subscriptionHistory']);
    }
}
