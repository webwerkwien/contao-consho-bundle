<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\EventListener\DataContainer;

use Contao\DataContainer;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\EventListener\DataContainer\VoucherListListener;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;

/**
 * WG13: Die Liste zeigt den Code in Gruppen und Geld als Geld — roh stünde
 * dort „50.0000" (Fall 30).
 */
class VoucherListListenerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TL_LANGUAGE'] = 'de';
        $GLOBALS['TL_LANG']['tl_consho_voucher'] = [
            'unlimited' => 'unbefristet', 'blockedShort' => 'gesperrt', 'sources' => ['manual' => 'Von Hand'],
            'code' => ['Code', ''], 'currency' => ['Währung', ''], 'balance' => ['Saldo', ''],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_LANGUAGE'], $GLOBALS['TL_LANG']['tl_consho_voucher']);
    }

    public function testCodeInGruppenGeldAlsGeld(): void
    {
        $listener = new VoucherListListener($this->createStub(Connection::class), new MoneyFormatter());

        $columns = $listener->voucher([
            'code' => 'ABCDEFGHJKMNPQRS', 'currency' => 'EUR', 'balance' => '12.5000', 'faceValue' => '50.0000',
            'validUntil' => null, 'source' => 'manual', 'blocked' => '1',
        ], '', $this->createStub(DataContainer::class), []);

        self::assertSame(
            ['ABCD-EFGH-JKMN-PQRS', '12,50 €', '50,00 €', 'unbefristet', 'Von Hand', 'gesperrt'],
            array_map(static fn (string $column): string => str_replace("\u{a0}", ' ', $column), $columns),
        );
    }

    /**
     * Abnahme WG-2: Der Kopf der Bewegungen zeigte „Saldo 20.0000".
     */
    public function testDerKopfDerBewegungenZeigtGeldAlsGeld(): void
    {
        $listener = new VoucherListListener($this->createStub(Connection::class), new MoneyFormatter());

        $labels = $listener->header(['Code' => 'ABCDEFGHJKMNPQRS', 'Währung' => 'EUR', 'Saldo' => '20.0000']);

        self::assertSame(['Code' => 'ABCD-EFGH-JKMN-PQRS', 'Währung' => 'EUR', 'Saldo' => '20,00 €'], array_map(static fn (string $value): string => str_replace("\u{a0}", ' ', $value), $labels));
    }
}
