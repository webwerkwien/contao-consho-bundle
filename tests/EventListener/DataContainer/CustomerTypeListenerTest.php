<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DC_Table;
use Contao\Message;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerType;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\CustomerTypeListener;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\SqliteWithoutRowLocks;

/**
 * The customer type decides the withdrawal period: businesses only get none,
 * consumers in the EU or the EEA cannot be left without one.
 */
class CustomerTypeListenerTest extends TestCase
{
    private Connection $connection;

    /** @var list<string> */
    private array $messages = [];

    protected function setUp(): void
    {
        $this->connection = SqliteWithoutRowLocks::connection();
        DcaSchema::create($this->connection, 'tl_consho_shop');

        $GLOBALS['TL_LANG']['tl_consho_shop']['customerTypeBusinessSet'] = 'B2B: 0';
        $GLOBALS['TL_LANG']['tl_consho_shop']['withdrawalDefaultsSet'] = 'Widerruf %s/%s';
        $GLOBALS['TL_LANG']['tl_consho_shop']['withdrawalZeroConsumer'] = 'keine 0';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANG']['tl_consho_shop']);
    }

    public function testNurUnternehmenSetztWiderrufAufNull(): void
    {
        $this->shop('business', 14, 7, ['AT']);

        $this->listener()->onSubmit($this->dc());

        self::assertSame([0, 0], $this->period());
        self::assertSame(['B2B: 0'], $this->messages);
    }

    public function testNurUnternehmenMitNullBleibtStill(): void
    {
        $this->shop('business', 0, 0, ['AT']);

        self::assertNull($this->listener()->align(1, 1_700_000_000));
        self::assertSame([0, 0], $this->period());
    }

    /**
     * The save as Contao runs it: field callbacks before the row is written,
     * onsubmit after.
     */
    public function testZurueckAufVerbraucherSetztVierzehnUndSieben(): void
    {
        $this->shop('business', 0, 0, ['AT', 'CH']);
        $listener = $this->listener();
        $dc = $this->dc(null);

        $listener->noteCustomerType('consumer', $dc);
        $this->connection->update('tl_consho_shop', ['customerType' => 'consumer'], ['id' => 1]);
        $listener->onSubmit($dc);

        self::assertSame([14, 7], $this->period());
        self::assertSame(['Widerruf 14/7'], $this->messages);
    }

    /**
     * A shop that already had 0 before the switch existed: saving the title
     * changes nothing.
     */
    public function testOhneAenderungBleibtBestehendeNull(): void
    {
        $this->shop('consumer', 0, 0, ['AT']);
        $listener = $this->listener();
        $dc = $this->dc(null);

        $listener->noteCustomerType('consumer', $dc);
        $listener->noteDeliveryCountries(serialize(['AT']), $dc);
        self::assertSame('0', $listener->refuseZero('0', $dc));
        $listener->onSubmit($dc);

        self::assertSame([0, 0], $this->period());
        self::assertSame([], $this->messages);
    }

    /**
     * An EU country added while the period is 0 sets the statutory period,
     * in the mask as on the console — the save itself is not refused.
     */
    public function testEuLandHinzuSetztVierzehnStattAbzulehnen(): void
    {
        $this->shop('consumer', 0, 0, ['CH']);
        $listener = $this->listener();
        $dc = $this->dc(['customerType' => 'consumer', 'deliveryCountries' => serialize(['CH', 'AT'])]);

        $listener->noteDeliveryCountries(['CH', 'AT'], $dc);
        self::assertSame('0', $listener->refuseZero('0', $dc));
        $this->connection->update('tl_consho_shop', ['deliveryCountries' => serialize(['CH', 'AT'])], ['id' => 1]);
        $listener->onSubmit($dc);

        self::assertSame([14, 7], $this->period());
    }

    public function testGleicheLaenderInAndererReihenfolgeSindKeineAenderung(): void
    {
        $this->shop('consumer', 0, 0, ['AT', 'DE']);
        $listener = $this->listener();

        $listener->noteDeliveryCountries(['DE', 'AT'], $this->dc());
        $listener->onSubmit($this->dc());

        self::assertSame([0, 0], $this->period());
    }

    /**
     * The flag belongs to one save: the next one starts clean.
     */
    public function testAenderungGiltNurFuerDiesesSpeichern(): void
    {
        $this->shop('consumer', 5, 0, ['AT']);
        $listener = $this->listener();

        $listener->noteDeliveryCountries(['DE'], $this->dc());
        $listener->onSubmit($this->dc());
        $this->connection->update('tl_consho_shop', ['withdrawalDays' => 0], ['id' => 1]);
        $listener->onSubmit($this->dc());

        self::assertSame([0, 0], $this->period());
    }

    public function testLeererWertWirdNichtAbgelehnt(): void
    {
        $this->shop('consumer', 14, 7, ['AT']);

        self::assertSame('', $this->listener()->refuseZero('', $this->dc()));
    }

    public function testVorhandeneNachfristBleibt(): void
    {
        $this->shop('consumer', 0, 3, []);

        self::assertSame('Widerruf 14/3', $this->listener()->align(1, 1_700_000_000));
        self::assertSame([14, 3], $this->period());
    }

    public function testVerbraucherAusserhalbDesEwrBehaeltNull(): void
    {
        $this->shop('consumer', 0, 0, ['CH', 'GB']);

        self::assertNull($this->listener()->align(1, 1_700_000_000));
        self::assertSame([0, 0], $this->period());
    }

    public function testVerbraucherMitFristBleibtUnberuehrt(): void
    {
        $this->shop('consumer', 30, 0, ['AT']);

        self::assertNull($this->listener()->align(1, 1_700_000_000));
        self::assertSame([30, 0], $this->period());
    }

    public function testNullWirdFuerVerbraucherInDerEuAbgelehnt(): void
    {
        $this->shop('consumer', 14, 7, ['DE']);

        $this->expectExceptionMessage('keine 0');
        $this->listener()->refuseZero('0', $this->dc());
    }

    public function testLeereLieferlisteGiltAlsEuLieferung(): void
    {
        $this->shop('consumer', 14, 7, []);

        $this->expectExceptionMessage('keine 0');
        $this->listener()->refuseZero(0, $this->dc());
    }

    public function testNullFuerVerbraucherNurAusserhalbErlaubt(): void
    {
        $this->shop('consumer', 14, 7, ['CH']);

        self::assertSame('0', $this->listener()->refuseZero('0', $this->dc()));
    }

    public function testNullFuerUnternehmenErlaubt(): void
    {
        $this->shop('business', 14, 7, ['AT']);

        self::assertSame('0', $this->listener()->refuseZero('0', $this->dc()));
    }

    public function testPositiverWertWirdNichtGeprueft(): void
    {
        $this->shop('consumer', 14, 7, ['AT']);

        self::assertSame('14', $this->listener()->refuseZero('14', $this->dc()));
    }

    /**
     * The mask lays the submitted countries over the active record: an EU
     * country added in the same save counts.
     */
    public function testLaenderDesAktivenDatensatzesZaehlen(): void
    {
        $this->shop('consumer', 14, 7, ['CH']);

        $this->expectExceptionMessage('keine 0');
        $this->listener()->refuseZero('0', $this->dc(['customerType' => 'consumer', 'deliveryCountries' => serialize(['CH', 'AT'])]));
    }

    /**
     * On the console the record being saved carries the new values.
     */
    public function testAktiverDatensatzGehtVorGespeichertem(): void
    {
        $this->shop('consumer', 14, 7, ['AT']);

        self::assertSame('0', $this->listener()->refuseZero('0', $this->dc(['customerType' => 'business'])));
    }

    public function testUnbekannterKundentypGiltAlsVerbraucher(): void
    {
        self::assertSame(CustomerType::Consumer, CustomerType::of('reseller'));
        self::assertSame(CustomerType::Consumer, CustomerType::of(null));
        self::assertSame(CustomerType::Business, CustomerType::of('business'));
    }

    /**
     * @param list<string> $countries
     */
    private function shop(string $type, int $days, int $grace, array $countries): void
    {
        $this->connection->insert('tl_consho_shop', [
            'id' => 1,
            'tstamp' => 1,
            'title' => 'Shop',
            'customerType' => $type,
            'withdrawalDays' => $days,
            'withdrawalGraceDays' => $grace,
            'deliveryCountries' => [] === $countries ? null : serialize($countries),
        ]);
    }

    /**
     * @return array{int, int}
     */
    private function period(): array
    {
        $row = $this->connection->fetchAssociative('SELECT withdrawalDays, withdrawalGraceDays FROM tl_consho_shop WHERE id = 1');
        self::assertIsArray($row);

        return [(int) $row['withdrawalDays'], (int) $row['withdrawalGraceDays']];
    }

    /**
     * @param array<string, mixed>|null $active null as DC_Table returns it without a record
     */
    private function dc(array|null $active = []): DC_Table
    {
        $dc = $this->createMock(DC_Table::class);
        $dc->method('__get')->willReturnCallback(static fn (string $key): mixed => 'id' === $key ? 1 : null);
        $dc->method('getActiveRecord')->willReturn($active);

        return $dc;
    }

    private function listener(): CustomerTypeListener
    {
        $message = $this->createMock(Adapter::class);
        $message->method('__call')->willReturnCallback(function (string $method, array $arguments): null {
            if ('addConfirmation' === $method) {
                $this->messages[] = (string) $arguments[0];
            }

            return null;
        });

        $framework = $this->createMock(ContaoFramework::class);
        $framework->method('getAdapter')->with(Message::class)->willReturn($message);

        return new CustomerTypeListener($this->connection, $framework);
    }
}
