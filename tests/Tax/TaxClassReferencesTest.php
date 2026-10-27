<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tax\TaxClassReferences;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Subscription\SubscriptionSchema;

class TaxClassReferencesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        foreach (['tl_consho_tax_class', 'tl_consho_product', 'tl_consho_shipping_method', 'tl_consho_payment_method', 'tl_consho_voucher'] as $table) {
            DcaSchema::create($this->connection, $table);
        }
        SubscriptionSchema::create($this->connection);

        $this->connection->insert('tl_consho_tax_class', ['id' => 1, 'title' => 'Allgemeine Waren']);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA'], $GLOBALS['TL_LANG']);
    }

    public function testOhneVerwaisteVerweiseMeldetEsNichts(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Wanderstock', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 1]);

        self::assertSame([], $this->references()->orphans());
    }

    public function testEinVerweisAufEineGeloeschteKlasseWirdGenannt(): void
    {
        // The path the voter cannot close: the product was deleted before the
        // class and came back from the recycle bin afterwards.
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Wanderstock', 'taxClass' => 99]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 99]);

        self::assertSame(
            [
                ['table' => 'tl_consho_product', 'id' => 5, 'title' => 'Wanderstock', 'taxClass' => 99],
                ['table' => 'tl_consho_shipping_method', 'id' => 6, 'title' => 'Paket', 'taxClass' => 99],
            ],
            $this->references()->orphans(),
        );
    }

    /**
     * 🔴 **An earlier draft excluded `taxClass = 0`**, reasoning that a fresh
     * catalogue would otherwise fill the section with rows.
     *
     * Checked, the reasoning was wrong twice: the field is `mandatory` in both
     * forms, so a row saved through the backend cannot be 0 at all; and a
     * variant carries its **own** class, so it inherits none (for example
     * `pid = 1`, `taxClass = 2`). There is no legitimate 0 that could cause
     * noise — a 0 comes from an import, from raw SQL or from an installation
     * from before the field.
     *
     * And the damage is the same: `findRate()` answers `null` for 0 as for 99.
     */
    public function testAuchEineLeereZuordnungIstEinVerwaisterVerweis(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Ohne Klasse', 'taxClass' => 0]);

        self::assertSame(
            [['table' => 'tl_consho_product', 'id' => 5, 'title' => 'Ohne Klasse', 'taxClass' => 0]],
            $this->references()->orphans(),
        );
    }

    /**
     * For the shipping method it weighs more heavily than for the product:
     * `DbalShippingMethodRepository` filters `taxClass > 0`, so the method
     * disappears from the selection without anything being said anywhere.
     */
    public function testEineVersandartOhneKlasseWirdEbenfallsGenannt(): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 0]);

        self::assertSame(
            [['table' => 'tl_consho_shipping_method', 'id' => 6, 'title' => 'Paket', 'taxClass' => 0]],
            $this->references()->orphans(),
        );
    }

    /**
     * E-1: A payment method only needs a fee tax class with a fixed fee. Its
     * 0 means "none", not an orphaned reference — a deleted class, on the
     * other hand, is one, and it holds on to the class.
     */
    public function testEineZahlungsartOhneGebuehrenklasseIstNichtVerwaist(): void
    {
        $this->connection->insert('tl_consho_payment_method', ['id' => 3, 'title' => 'Vorkasse', 'feeTaxClass' => 0]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 4, 'title' => 'Nachnahme', 'feeTaxClass' => 99]);
        $this->connection->insert('tl_consho_payment_method', ['id' => 5, 'title' => 'Nachnahme AT', 'feeTaxClass' => 1]);

        $orphans = array_values(array_filter($this->references()->orphans(), static fn (array $o): bool => 'tl_consho_payment_method' === $o['table']));

        self::assertSame([['table' => 'tl_consho_payment_method', 'id' => 4, 'title' => 'Nachnahme', 'taxClass' => 99]], $orphans);
        self::assertTrue($this->references()->isInUse(1), 'Die Gebühren-Klasse einer Zahlungsart hält die Klasse fest.');
    }

    /**
     * Abo-3: The subscription freezes the class, not the rate (A9). Every
     * follow-up billing asks for the class anew — without it, no invoice.
     */
    public function testEinAboHaeltSeineSteuerklasse(): void
    {
        $this->connection->insert('tl_consho_subscription', ['subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 1]);

        self::assertTrue($this->references()->isInUse(1));
    }

    /**
     * Abo-6 (P51): An ended subscription is never billed again — it no longer
     * holds the class and is not an orphan either. Suspended and cancelled
     * ones are still running.
     */
    public function testNurEinLaufendesAboHaeltDieKlasse(): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => 4, 'subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 1, 'state' => 'ended']);
        $this->connection->insert('tl_consho_subscription', ['id' => 5, 'subscriptionNumber' => 'WEB-00091-1', 'taxClass' => 99, 'state' => 'ended']);

        self::assertFalse($this->references()->isInUse(1));
        self::assertSame([], $this->references()->orphans());

        foreach (['suspended', 'cancelled'] as $state) {
            $this->connection->update('tl_consho_subscription', ['state' => $state], ['id' => 4]);
            self::assertTrue($this->references()->isInUse(1), $state);
        }
    }

    /**
     * EZ-5: a single-purpose voucher with a balance holds its class; a spent
     * or blocked one does not, and a multi-purpose one (0) is no orphan.
     */
    public function testEinEinzweckGutscheinMitGuthabenHaeltSeineKlasse(): void
    {
        $this->connection->insert('tl_consho_voucher', ['id' => 7, 'code' => 'AAAABBBBCCCCDDDD', 'taxClass' => 1, 'balance' => '0.0000']);
        $this->connection->insert('tl_consho_voucher', ['id' => 8, 'code' => 'EEEEFFFFGGGGHHHH', 'taxClass' => 1, 'balance' => '10.0000', 'blocked' => 1]);
        $this->connection->insert('tl_consho_voucher', ['id' => 9, 'code' => 'JJJJKKKKMMMMNNNN', 'taxClass' => 0, 'balance' => '10.0000']);

        self::assertFalse($this->references()->isInUse(1));
        self::assertSame([], $this->references()->orphans());

        $this->connection->update('tl_consho_voucher', ['blocked' => 0], ['id' => 8]);
        self::assertTrue($this->references()->isInUse(1));

        $this->connection->update('tl_consho_voucher', ['taxClass' => 99], ['id' => 8]);
        self::assertSame([['table' => 'tl_consho_voucher', 'id' => 8, 'title' => 'EEEEFFFFGGGGHHHH', 'taxClass' => 99]], $this->references()->orphans());
    }

    /**
     * The subscription has no `title` column — the query names it by its number.
     */
    public function testEinVerwaistesAboWirdMitSeinerNummerGenannt(): void
    {
        $this->connection->insert('tl_consho_subscription', ['id' => 4, 'subscriptionNumber' => 'WEB-00090-1', 'taxClass' => 99]);

        self::assertSame(
            [['table' => 'tl_consho_subscription', 'id' => 4, 'title' => 'WEB-00090-1', 'taxClass' => 99]],
            $this->references()->orphans(),
        );
    }

    public function testInBenutzungFragtBeideTabellen(): void
    {
        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 1]);

        self::assertTrue($this->references()->isInUse(1));
        self::assertFalse($this->references()->isInUse(2));
        // No query for an ID that cannot exist.
        self::assertFalse($this->references()->isInUse(0));
    }

    /**
     * CW-5: who holds the class, for a rejection that names them — at most as
     * many as requested, across all tables together.
     */
    public function testUsedByNenntHoechstensDieVerlangteZahl(): void
    {
        foreach (range(1, 4) as $id) {
            $this->connection->insert('tl_consho_product', ['id' => $id, 'title' => 'P'.$id, 'taxClass' => 1]);
        }

        $this->connection->insert('tl_consho_shipping_method', ['id' => 6, 'title' => 'Paket', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_shipping_method', ['id' => 7, 'title' => 'Express', 'taxClass' => 1]);
        $this->connection->insert('tl_consho_subscription', ['id' => 8, 'subscriptionNumber' => 'WEB-1', 'taxClass' => 1]);

        self::assertSame(
            ['tl_consho_product 1', 'tl_consho_product 2', 'tl_consho_product 3', 'tl_consho_product 4', 'tl_consho_shipping_method 6'],
            $this->references()->usedBy(1),
        );
        self::assertSame(['tl_consho_product 1', 'tl_consho_product 2'], $this->references()->usedBy(1, 2));
    }

    public function testUsedByOhneKlasse(): void
    {
        // A product without a class does not hold a "class 0".
        $this->connection->insert('tl_consho_product', ['id' => 5, 'title' => 'Ohne', 'taxClass' => 0]);

        self::assertSame([], $this->references()->usedBy(0));
    }

    /**
     * 🔴 **The list of referencing tables must not be a claim.**
     *
     * It stands as a constant in the class, and a constant nobody checks reads
     * like knowledge.
     *
     * Here it comes from the DCAs themselves: every field whose `foreignKey`
     * names this table. If a third table with a tax class is added, this test
     * fails and names it, instead of the deletion protection silently covering
     * less than it claims.
     *
     * ⚠️ **The rates are excluded**, with a reason, not left out:
     * `tl_consho_tax_rate.pid` names the same table, but the rates are child
     * rows (`ptable`) and go to the recycle bin with the class. Holding on to
     * them would mean a class with rates could never be deleted — so
     * practically no class could.
     */
    public function testDieListeDerVerweisendenTabellenIstVollstaendig(): void
    {
        $found = [];

        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            unset($GLOBALS['TL_DCA']);

            // See EveryDcaSurvivesContaoMigrationsTest: two files extend core
            // tables and reach for a palette that Contao declares.
            $GLOBALS['TL_DCA']['tl_member']['palettes']['default'] = '{personal_legend},firstname,lastname;';
            $GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = '{title_legend},title,alias;';
            $GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'] = '{title_legend},title,alias;';
            $GLOBALS['TL_DCA']['tl_module']['palettes']['registration'] = '{title_legend},name,type;{email_legend},reg_activate;';

            require $file;

            foreach ($GLOBALS['TL_DCA'] ?? [] as $table => $definition) {
                $childOf = 'tl_consho_tax_class' === ($definition['config']['ptable'] ?? null);

                foreach ($definition['fields'] ?? [] as $field => $config) {
                    /*
                     * Only the `pid` of a child table is excluded, not the whole
                     * table — otherwise a second field next to it would silently
                     * drop out of the check.
                     */
                    if ($childOf && 'pid' === $field) {
                        continue;
                    }

                    if (str_starts_with((string) ($config['foreignKey'] ?? ''), 'tl_consho_tax_class.')) {
                        $found[$table] = $field;
                    }
                }
            }
        }

        ksort($found);
        $expected = TaxClassReferences::REFERENCES;
        ksort($expected);

        self::assertSame(
            $expected,
            $found,
            'TaxClassReferences::REFERENCES and the DCAs disagree about who points at a tax class. '
            .'A table missing from the constant is a table the delete guard does not protect.',
        );
    }

    private function references(): TaxClassReferences
    {
        return new TaxClassReferences($this->connection);
    }
}
