<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRules;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Regel 1 ist ein Monatsabo für alle, Regel 2 ein Jahresabo nur für die Gruppen 3 und 4.
 */
class SubscriptionRulesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        // Aus der DCA (Fall 31): die abgeschriebene Tabelle kannte `pauseMonths`
        // nicht (Abo pausieren). `groups` ist in MySQL 8 reserviert — das
        // Repository muss es beim Lesen quoten.
        DcaSchema::create($this->connection, 'tl_consho_subscription_rule');
        $this->connection->insert('tl_consho_subscription_rule', ['id' => 1, 'title' => 'Monatsabo', 'billingInterval' => 1, 'renewal' => 'indefinite']);
        $this->connection->insert('tl_consho_subscription_rule', [
            'id' => 2,
            'title' => 'Jahresabo',
            'renewal' => 'sameTerm',
            $this->connection->quoteIdentifier('groups') => serialize(['3', '4']),
        ]);
    }

    public function testLiestRegelnInDerGefragtenReihenfolge(): void
    {
        $rules = (new SubscriptionRules($this->connection))->byIds([2, 1, 99]);

        self::assertSame([2, 1], array_keys($rules));
        self::assertSame('Jahresabo', $rules[2]->title);
        self::assertSame(RenewalKind::SameTerm, $rules[2]->renewal);
        self::assertSame([3, 4], $rules[2]->groups);
        self::assertSame(12, $rules[2]->termMonths());
        self::assertSame(1, $rules[1]->billingInterval);
        self::assertSame(RenewalKind::Indefinite, $rules[1]->renewal);
        self::assertSame([], $rules[1]->groups);
        self::assertSame(0, $rules[1]->pauseMonths, 'Vorgabe: nicht pausierbar (P102)');
    }

    public function testDiePauseWirdGelesen(): void
    {
        $this->connection->update('tl_consho_subscription_rule', ['pauseMonths' => 3], ['id' => 1]);

        self::assertSame(3, (new SubscriptionRules($this->connection))->byIds([1])[1]->pauseMonths);
    }

    public function testLeereListeFragtNichts(): void
    {
        self::assertSame([], (new SubscriptionRules($this->connection))->byIds([]));
    }

    public function testGruppenWieBeiGruppenpreisen(): void
    {
        $rules = (new SubscriptionRules($this->connection))->byIds([1, 2]);

        // Leer gilt für alle, sonst genügt eine gemeinsame Gruppe.
        self::assertTrue($rules[1]->isFor([]));
        self::assertFalse($rules[2]->isFor([]));
        self::assertFalse($rules[2]->isFor([5]));
        self::assertTrue($rules[2]->isFor([5, 4]));
    }
}
