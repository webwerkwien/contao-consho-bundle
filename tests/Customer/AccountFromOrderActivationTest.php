<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Contao\StringUtil;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Webwerkwien\ContaoConshoBundle\Cron\AccountFromOrderCron;
use Webwerkwien\ContaoConshoBundle\Customer\AccountFromOrderActivation;
use Webwerkwien\ContaoConshoBundle\Customer\MemberOrders;
use Webwerkwien\ContaoConshoBundle\Customer\RegistrationActivation;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * K1-6: the first password opens an account created from a guest order —
 * open, in the shop's customer group, address confirmed. An account never
 * opened goes after 24 hours. Every other account stays as it is.
 */
final class AccountFromOrderActivationTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::createCore($this->connection, 'tl_member');
        DcaSchema::createCore($this->connection, 'tl_page');
        DcaSchema::create($this->connection, 'tl_consho_shop');
        $this->connection->executeStatement("CREATE TABLE tl_opt_in (id INTEGER PRIMARY KEY, token TEXT NOT NULL DEFAULT '', confirmedOn INTEGER NOT NULL DEFAULT 0)");
        $this->connection->executeStatement("CREATE TABLE tl_opt_in_related (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, relTable TEXT NOT NULL DEFAULT '', relId INTEGER NOT NULL DEFAULT 0)");

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Webshop', 'customerGroup' => 7]);
        $this->connection->insert('tl_page', ['id' => 1, 'type' => 'root', 'title' => 'Shop', 'consho_shop' => 1]);

        // As AccountFromOrder created it, after Contao saved the password.
        $this->connection->insert('tl_member', ['id' => 5, 'email' => 'Kundin@Example.org', 'username' => 'Kundin@Example.org', 'login' => 1, 'disable' => 1, 'password' => 'hash', 'consho_accountPending' => self::NOW - 600]);
    }

    public function testDasErstePasswortOeffnetDasKonto(): void
    {
        $this->activation()->activate(5, 1, self::NOW);

        $member = $this->connection->fetchAssociative('SELECT disable, login, consho_accountPending, consho_confirmedEmail, consho_confirmedEmailAt, "groups" AS grp FROM tl_member WHERE id = 5');
        self::assertIsArray($member);
        self::assertSame(0, (int) $member['disable']);
        self::assertSame(1, (int) $member['login']);
        self::assertSame(0, (int) $member['consho_accountPending']);
        self::assertSame(['7'], StringUtil::deserialize($member['grp'], true), 'Basisgruppe des Shops');
        self::assertSame('kundin@example.org', $member['consho_confirmedEmail'], 'der Klick belegt die Adresse');
        self::assertSame(self::NOW, (int) $member['consho_confirmedEmailAt']);
    }

    public function testEinGewoehnlichesKontoBleibtUnberuehrt(): void
    {
        $this->connection->insert('tl_member', ['id' => 6, 'email' => 'andere@example.org', 'username' => 'andere', 'login' => 1, 'disable' => 1, 'password' => 'hash']);

        $this->activation()->activate(6, 1, self::NOW);

        self::assertSame(
            ['disable' => 1, 'consho_confirmedEmail' => ''],
            array_map(static fn ($v) => is_numeric($v) ? (int) $v : $v, $this->connection->fetchAssociative('SELECT disable, consho_confirmedEmail FROM tl_member WHERE id = 6') ?: []),
            'ein gesperrtes Konto des Betreibers öffnet „Passwort vergessen" nicht',
        );
    }

    public function testDerCronLoeschtNurNieGeoeffneteKontenNach24Stunden(): void
    {
        $this->connection->update('tl_member', ['password' => ''], ['id' => 5]);
        $this->connection->insert('tl_member', ['id' => 6, 'email' => 'frisch@example.org', 'username' => 'frisch@example.org', 'disable' => 1, 'consho_accountPending' => self::NOW - 60]);
        $this->connection->insert('tl_member', ['id' => 7, 'email' => 'gesperrt@example.org', 'username' => 'gesperrt', 'disable' => 1]);

        $cron = new AccountFromOrderCron($this->connection);

        self::assertSame(0, $cron->run(self::NOW), 'innerhalb der 24 Stunden bleibt alles');
        self::assertSame(1, $cron->run(self::NOW - 300 + AccountFromOrderCron::PENDING_FOR), 'nur das Konto von vor zehn Minuten');
        self::assertSame([6, 7], array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_member ORDER BY id')));
    }

    public function testDerCronNimmtDieOffenenTokensDesKontosMit(): void
    {
        $this->connection->update('tl_member', ['password' => ''], ['id' => 5]);
        // Two links of the pending account, and a token of another member.
        $this->connection->insert('tl_opt_in', ['id' => 1, 'token' => 'pw-a']);
        $this->connection->insert('tl_opt_in', ['id' => 2, 'token' => 'pw-b']);
        $this->connection->insert('tl_opt_in', ['id' => 3, 'token' => 'reg-c']);
        $this->connection->insert('tl_opt_in_related', ['pid' => 1, 'relTable' => 'tl_member', 'relId' => 5]);
        $this->connection->insert('tl_opt_in_related', ['pid' => 2, 'relTable' => 'tl_member', 'relId' => 5]);
        $this->connection->insert('tl_opt_in_related', ['pid' => 3, 'relTable' => 'tl_member', 'relId' => 6]);

        // Exactly 24 hours after the first request.
        self::assertSame(1, (new AccountFromOrderCron($this->connection))->run(self::NOW - 600 + AccountFromOrderCron::PENDING_FOR));

        self::assertSame([3], array_map('intval', $this->connection->fetchFirstColumn('SELECT id FROM tl_opt_in')));
        self::assertSame([6], array_map('intval', $this->connection->fetchFirstColumn('SELECT relId FROM tl_opt_in_related')));
    }

    public function testEinGeoeffnetesKontoLoeschtDerCronNie(): void
    {
        $this->activation()->activate(5, 1, self::NOW);

        self::assertSame(0, (new AccountFromOrderCron($this->connection))->run(self::NOW + 10 * AccountFromOrderCron::PENDING_FOR));
    }

    private function activation(): AccountFromOrderActivation
    {
        return new AccountFromOrderActivation($this->connection, new RegistrationActivation($this->connection, new RequestStack()), new MemberOrders($this->connection));
    }
}
