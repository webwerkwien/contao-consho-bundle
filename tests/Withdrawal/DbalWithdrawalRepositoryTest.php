<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Withdrawal;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Withdrawal\DbalWithdrawalRepository;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * F-4, F17: Freigegebene digitale Mengen sind nicht mehr widerrufbar — die
 * Freigabe geschieht nur mit Zustimmung, bei Geschäftskunden oder nach
 * Fristende (§ 18 Abs. 1 Z 11 FAGG, § 356 Abs. 6 BGB).
 */
class DbalWithdrawalRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        WithdrawalSchema::create($this->connection);
        DcaSchema::create($this->connection, 'tl_consho_order');
        $this->connection->insert('tl_consho_order', ['id' => 7]);
        DcaSchema::create($this->connection, 'tl_consho_order_item');

        $this->connection->insert('tl_consho_order_item', ['id' => 1, 'pid' => 7, 'productName' => 'Buch', 'quantity' => '1']);
        $this->connection->insert('tl_consho_order_item', ['id' => 2, 'pid' => 7, 'productName' => 'E-Book', 'quantity' => '3', 'kind' => 'digital']);
    }

    public function testFreigegebeneDigitaleMengeIstNichtMehrWiderrufbar(): void
    {
        $repository = new DbalWithdrawalRepository($this->connection);

        self::assertEquals(3, $repository->goods(7)[2]['left'], 'vor der Freigabe: widerrufbar');

        $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => 2, 'quantity' => '3']);
        $goods = $repository->goods(7);

        self::assertEquals(0, $goods[2]['left']);
        self::assertEquals(1, $goods[1]['left'], 'das Buch behält sein Fenster');
    }

    /**
     * Abo-8, P76: Eine Dienstleistung bleibt nach der Freigabe widerrufbar —
     * die Zustimmung lässt das Recht bestehen, sie schuldet nur Wertersatz
     * (§ 357a Abs. 2 BGB). Gegenprobe: dieselbe Freigabe an einem digitalen
     * Inhalt verbraucht die Menge.
     */
    public function testFreigegebeneDienstleistungBleibtWiderrufbar(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 2]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => 2, 'quantity' => '3']);

        $repository = new DbalWithdrawalRepository($this->connection);
        self::assertEquals(3, $repository->goods(7)[2]['left']);

        $this->connection->update('tl_consho_order_item', ['kind' => 'digital'], ['id' => 2]);
        self::assertEquals(0, $repository->goods(7)[2]['left']);
    }

    /**
     * Review Abo-8, S1: Für einen Geschäftskunden verbraucht die Freigabe auch
     * eine Dienstleistung — wie seit F-4 einen digitalen Inhalt.
     */
    public function testBeimGeschaeftskundenVerbrauchtDieFreigabeAuchDieDienstleistung(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service'], ['id' => 2]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => 2, 'quantity' => '3']);
        $this->connection->update('tl_consho_order', ['businessCustomer' => 1], ['id' => 7]);

        self::assertEquals(0, (new DbalWithdrawalRepository($this->connection))->goods(7)[2]['left']);
    }

    /**
     * DL3: „Leistung erbracht" mit Zustimmung zum vorzeitigen Beginn beendet
     * das Recht (§ 356 Abs. 4 BGB, § 18 Abs. 1 Z 1 FAGG); ohne Zustimmung
     * nicht. Das Buch daneben behält sein Fenster.
     */
    public function testErbrachteLeistungMitZustimmungIstNichtMehrWiderrufbar(): void
    {
        $this->connection->update('tl_consho_order_item', ['kind' => 'service', 'performedAt' => 1_000], ['id' => 2]);
        $this->connection->insert('tl_consho_entitlement', ['pid' => 7, 'orderItem' => 2, 'quantity' => '3']);
        $repository = new DbalWithdrawalRepository($this->connection);

        self::assertEquals(3, $repository->goods(7)[2]['left'], 'ohne Zustimmung: widerrufbar, Wertersatz');

        $this->connection->update('tl_consho_order', ['digitalConsentAt' => 900], ['id' => 7]);
        $goods = $repository->goods(7);

        self::assertEquals(0, $goods[2]['left']);
        self::assertEquals(1, $goods[1]['left'], 'das Buch behält sein Fenster');

        $this->connection->update('tl_consho_order_item', ['performedAt' => null], ['id' => 2]);
        self::assertEquals(3, $repository->goods(7)[2]['left'], 'mit Zustimmung, aber nicht erbracht: widerrufbar');
    }

    /**
     * Abo-8, P77: Eine Abo-Zeile ist nur ganz widerrufbar.
     */
    public function testAboZeileIstNurGanz(): void
    {
        $this->connection->update('tl_consho_order_item', ['subscriptionRule' => 3], ['id' => 2]);
        $goods = (new DbalWithdrawalRepository($this->connection))->goods(7);

        self::assertTrue($goods[2]['whole']);
        self::assertFalse($goods[1]['whole']);
    }

    /**
     * Abnahme Abo-8: „Die ganze Bestellung" legt keine Positionen an. Ohne
     * eigene Regel blieb danach jede Menge übrig, und die Bestellseite bot
     * den Widerruf weiter an. Gegenprobe: ein Teil als Freitext nennt keine
     * Menge und lässt alles stehen.
     */
    public function testNachWiderrufDerGanzenBestellungIstNichtsMehrUebrig(): void
    {
        $repository = new DbalWithdrawalRepository($this->connection);

        $this->connection->insert('tl_consho_withdrawal', ['pid' => 7, 'number' => 'W1', 'scope' => 'part', 'part' => 'das Buch']);
        self::assertEquals(1, $repository->goods(7)[1]['left'], 'Freitext: nichts verbraucht');

        $this->connection->insert('tl_consho_withdrawal', ['pid' => 7, 'number' => 'W2', 'scope' => 'all']);
        $goods = $repository->goods(7);

        self::assertEquals(0, $goods[1]['left']);
        self::assertEquals(0, $goods[2]['left']);
    }

    public function testDieGanzeBestellungEinerAnderenBestellungZaehltNicht(): void
    {
        $this->connection->insert('tl_consho_withdrawal', ['pid' => 8, 'number' => 'W1', 'scope' => 'all']);

        self::assertEquals(1, (new DbalWithdrawalRepository($this->connection))->goods(7)[1]['left']);
    }
}
