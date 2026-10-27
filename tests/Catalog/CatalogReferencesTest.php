<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Catalog;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\CatalogReferences;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Connect stage 2, CW-4b (H4): who still uses a product type, manufacturer,
 * property or one of its values.
 */
class CatalogReferencesTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        foreach (['tl_consho_product', 'tl_consho_product_type', 'tl_consho_manufacturer', 'tl_consho_property_group', 'tl_consho_property_option', 'tl_consho_product_property', 'tl_consho_product_option', 'tl_consho_price_rule', 'tl_consho_cart_rule', 'tl_consho_shop', 'tl_consho_order', 'tl_consho_subscription', 'tl_consho_voucher', 'tl_consho_product_shop', 'tl_consho_cart', 'tl_consho_price', 'tl_consho_shipping_method', 'tl_consho_payment_method'] as $table) {
            DcaSchema::create($this->connection, $table);
        }

        $this->connection->executeStatement('CREATE TABLE tl_content (id INTEGER PRIMARY KEY AUTOINCREMENT, type TEXT NOT NULL DEFAULT \'\', conshoFilter BLOB NULL)');
        $this->connection->executeStatement('CREATE TABLE tl_page (id INTEGER PRIMARY KEY AUTOINCREMENT, consho_shop INTEGER NOT NULL DEFAULT 0)');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']);
    }

    public function testEinProdukttypAnEinemProdukt(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'productType' => 2]);

        self::assertSame(['tl_consho_product 5'], $this->references()->usedBy('tl_consho_product_type', 2));
        self::assertFalse($this->references()->isInUse('tl_consho_product_type', 3));
    }

    /**
     * In a list only the whole value counts: 5 is also contained in 15 and in
     * the index "i:5;" of the serialised list.
     */
    public function testInEinerRegelZaehltNurDerGanzeWert(): void
    {
        $this->connection->insert('tl_consho_price_rule', ['id' => 3, 'productTypes' => serialize(['15', '2'])]);
        $this->connection->insert('tl_consho_cart_rule', ['id' => 4, 'productTypes' => serialize([0 => '1', 5 => '7'])]);

        self::assertFalse($this->references()->isInUse('tl_consho_product_type', 5));
        self::assertSame(['tl_consho_price_rule 3'], $this->references()->usedBy('tl_consho_product_type', 15));
        self::assertSame(['tl_consho_cart_rule 4'], $this->references()->usedBy('tl_consho_product_type', 7));
    }

    public function testEinHersteller(): void
    {
        $this->connection->insert('tl_consho_product', ['id' => 5, 'manufacturer' => 2]);
        $this->connection->insert('tl_consho_manufacturer', ['id' => 2, 'euResponsible' => 2]);
        $this->connection->insert('tl_consho_manufacturer', ['id' => 3, 'euResponsible' => 4]);
        $this->connection->insert('tl_consho_cart_rule', ['id' => 6, 'manufacturers' => serialize(['9'])]);
        $this->connection->insert('tl_consho_price_rule', ['id' => 7, 'manufacturers' => serialize(['9'])]);

        self::assertSame(['tl_consho_product 5'], $this->references()->usedBy('tl_consho_manufacturer', 2), 'sich selbst als Verantwortlicher hält nicht fest');
        self::assertSame(['tl_consho_manufacturer 3'], $this->references()->usedBy('tl_consho_manufacturer', 4));
        self::assertSame(['tl_consho_price_rule 7', 'tl_consho_cart_rule 6'], $this->references()->usedBy('tl_consho_manufacturer', 9));
    }

    public function testEinWertAnProduktAchseRegelUndListe(): void
    {
        $this->connection->insert('tl_consho_product_property', ['pid' => 5, 'optionId' => 11]);
        $this->connection->insert('tl_consho_product_option', ['pid' => 6, 'optionId' => 12]);
        $this->connection->insert('tl_consho_price_rule', ['id' => 3, 'propertyOptions' => serialize(['13'])]);
        $this->connection->insert('tl_content', ['id' => 40, 'type' => 'consho_product_list', 'conshoFilter' => serialize(['14'])]);

        self::assertSame(['tl_consho_product 5'], $this->references()->usedBy('tl_consho_property_option', 11));
        self::assertSame(['tl_consho_product 6'], $this->references()->usedBy('tl_consho_property_option', 12));
        self::assertSame(['tl_consho_price_rule 3'], $this->references()->usedBy('tl_consho_property_option', 13));
        self::assertSame(['tl_content 40'], $this->references()->usedBy('tl_consho_property_option', 14));
    }

    /**
     * The property goes to the recycle bin with its values: in use
     * as soon as one of them is in use — or a product type names it.
     */
    public function testEineEigenschaftUeberIhreWerteUndDenProdukttyp(): void
    {
        $this->connection->insert('tl_consho_property_option', ['id' => 11, 'pid' => 1]);
        $this->connection->insert('tl_consho_property_option', ['id' => 21, 'pid' => 2]);
        $this->connection->insert('tl_consho_product_option', ['pid' => 6, 'optionId' => 11]);
        $this->connection->insert('tl_consho_product_type', ['id' => 8, 'propertyGroups' => serialize(['3'])]);

        self::assertSame(['tl_consho_product 6'], $this->references()->usedBy('tl_consho_property_group', 1));
        self::assertFalse($this->references()->isInUse('tl_consho_property_group', 2), 'Wert 21 hängt an nichts');
        self::assertSame(['tl_consho_product_type 8'], $this->references()->usedBy('tl_consho_property_group', 3));
    }

    /**
     * CW-5 (I6): every reference to the shop holds on to it — except an
     * open cart.
     */
    public function testEinShop(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 30, 'shop' => 1]);
        $this->connection->insert('tl_consho_subscription', ['id' => 31, 'shop' => 2]);
        $this->connection->insert('tl_consho_voucher', ['id' => 32, 'code' => 'A', 'shop' => 3]);
        $this->connection->insert('tl_consho_voucher', ['id' => 33, 'code' => 'B', 'shops' => serialize(['14', '4'])]);
        $this->connection->insert('tl_consho_cart_rule', ['id' => 34, 'shops' => serialize(['5'])]);
        $this->connection->insert('tl_consho_product_shop', ['pid' => 35, 'shop' => 6]);
        $this->connection->insert('tl_page', ['id' => 36, 'consho_shop' => 7]);
        $this->connection->insert('tl_consho_cart', ['id' => 37, 'shop' => 8]);

        self::assertSame(['tl_consho_order 30'], $this->references()->usedBy('tl_consho_shop', 1));
        self::assertSame(['tl_consho_subscription 31'], $this->references()->usedBy('tl_consho_shop', 2));
        self::assertSame(['tl_consho_voucher 32'], $this->references()->usedBy('tl_consho_shop', 3));
        self::assertSame(['tl_consho_voucher 33'], $this->references()->usedBy('tl_consho_shop', 4));
        self::assertSame(['tl_consho_cart_rule 34'], $this->references()->usedBy('tl_consho_shop', 5));
        self::assertSame(['tl_consho_product 35'], $this->references()->usedBy('tl_consho_shop', 6));
        self::assertSame(['tl_page 36'], $this->references()->usedBy('tl_consho_shop', 7));
        self::assertFalse($this->references()->isInUse('tl_consho_shop', 8), 'ein Warenkorb hält den Shop nicht fest');
    }

    public function testEinBestellstatusUndEinMailVersand(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 30, 'orderStatus' => 11]);
        $this->connection->insert('tl_consho_shop', ['id' => 1, 'statusAfterPayment' => 12, 'mailTransport' => 3]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'statusAfterDelivery' => 13]);
        $this->connection->insert('tl_consho_shop', ['id' => 4, 'statusWhenSettled' => 14]);

        self::assertSame(['tl_consho_order 30'], $this->references()->usedBy('tl_consho_order_status', 11));
        self::assertSame(['tl_consho_shop 1'], $this->references()->usedBy('tl_consho_order_status', 12));
        self::assertSame(['tl_consho_shop 2'], $this->references()->usedBy('tl_consho_order_status', 13));
        self::assertSame(['tl_consho_shop 4'], $this->references()->usedBy('tl_consho_order_status', 14));
        self::assertFalse($this->references()->isInUse('tl_consho_order_status', 15));
        self::assertSame(['tl_consho_shop 1'], $this->references()->usedBy('tl_consho_mail_transport', 3));
        self::assertFalse($this->references()->isInUse('tl_consho_mail_transport', 4));
    }

    /**
     * CW-5 (I1): shipping and payment method, subscription rule and tax class
     * ask the same place as mask and commands.
     */
    public function testArtenUndRegeln(): void
    {
        $this->connection->insert('tl_consho_order', ['id' => 30, 'shippingMethod' => 1, 'paymentMethod' => 2]);
        $this->connection->insert('tl_consho_subscription', ['id' => 31, 'subscriptionNumber' => 'A-1', 'mandateMethod' => 3, 'state' => 'active']);
        $this->connection->insert('tl_consho_subscription', ['id' => 32, 'subscriptionNumber' => 'A-2', 'mandateMethod' => 4, 'state' => 'ended']);
        $this->connection->insert('tl_consho_price', ['id' => 33, 'subscriptionRule' => 5, 'tstamp' => 1]);
        $this->connection->insert('tl_consho_price', ['id' => 34, 'subscriptionRule' => 6, 'tstamp' => 0]);
        $this->connection->insert('tl_consho_product', ['id' => 35, 'taxClass' => 7, 'subscriptionRules' => serialize(['8'])]);

        self::assertSame(['tl_consho_order 30'], $this->references()->usedBy('tl_consho_shipping_method', 1));
        self::assertSame(['tl_consho_order 30'], $this->references()->usedBy('tl_consho_payment_method', 2));
        self::assertSame(['tl_consho_subscription 31'], $this->references()->usedBy('tl_consho_payment_method', 3), 'ein laufendes Abo bucht darüber ab');
        self::assertFalse($this->references()->isInUse('tl_consho_payment_method', 4), 'ein beendetes Abo bucht nie mehr ab');
        self::assertSame(['tl_consho_price 33'], $this->references()->usedBy('tl_consho_subscription_rule', 5));
        self::assertFalse($this->references()->isInUse('tl_consho_subscription_rule', 6), 'eine nie gespeicherte Preiszeile ist kein Preis');
        self::assertFalse($this->references()->isInUse('tl_consho_subscription_rule', 8), 'die Auswahl am Produkt hält die Regel nicht');
        self::assertSame(['tl_consho_product 35'], $this->references()->usedBy('tl_consho_tax_class', 7));
        self::assertTrue(CatalogReferences::covers('tl_consho_tax_class'));
    }

    public function testHoechstensFuenfGenannt(): void
    {
        foreach (range(1, 7) as $id) {
            $this->connection->insert('tl_consho_product', ['id' => $id, 'productType' => 2]);
        }

        self::assertCount(5, $this->references()->usedBy('tl_consho_product_type', 2));
    }

    public function testHoechstensFuenfUeberAlleVerweiseZusammen(): void
    {
        foreach (range(1, 4) as $id) {
            $this->connection->insert('tl_consho_product', ['id' => $id, 'productType' => 2]);
            $this->connection->insert('tl_consho_price_rule', ['id' => $id, 'productTypes' => serialize(['2'])]);
        }

        self::assertSame(
            ['tl_consho_product 1', 'tl_consho_product 2', 'tl_consho_product 3', 'tl_consho_product 4', 'tl_consho_price_rule 1'],
            $this->references()->usedBy('tl_consho_product_type', 2),
        );
    }

    public function testAndereTabellenUndKeineId(): void
    {
        // A product without a type does not hold on to a "type 0".
        $this->connection->insert('tl_consho_product', ['id' => 5]);

        self::assertSame([], $this->references()->usedBy('tl_consho_custom_field', 1));
        self::assertSame([], $this->references()->usedBy('tl_consho_product_type', 0));
        self::assertFalse(CatalogReferences::covers('tl_consho_custom_field'));
        self::assertFalse(CatalogReferences::covers('tl_consho_number_range'), 'Nummernkreise löscht kein Befehl (I1)');
        self::assertTrue(CatalogReferences::covers('tl_consho_manufacturer'));
        self::assertTrue(CatalogReferences::covers('tl_consho_shop'));
    }

    /**
     * 🔴 The list of references must not be a claim (as with the
     * tax class, TaxClassReferencesTest): it comes from the DCAs — every
     * field whose `foreignKey` or `relation.table` names one of the covered
     * tables, without the `pid` of a child table and without NOT_COUNTED. Plus
     * only the references whose field names no table (WITHOUT_DCA_LINK).
     */
    public function testDieListeDerVerweiseIstVollstaendig(): void
    {
        $found = [];

        foreach (glob(__DIR__.'/../../contao/dca/*.php') ?: [] as $file) {
            unset($GLOBALS['TL_DCA']);

            // See TaxClassReferencesTest: files that extend core tables.
            $GLOBALS['TL_DCA']['tl_member']['palettes']['default'] = '{personal_legend},firstname,lastname;';
            $GLOBALS['TL_DCA']['tl_page']['palettes']['root'] = '{title_legend},title,alias;';
            $GLOBALS['TL_DCA']['tl_page']['palettes']['rootfallback'] = '{title_legend},title,alias;';
            $GLOBALS['TL_DCA']['tl_module']['palettes']['registration'] = '{title_legend},name,type;{email_legend},reg_activate;';

            require $file;

            foreach ($GLOBALS['TL_DCA'] ?? [] as $table => $definition) {
                foreach ($definition['fields'] ?? [] as $field => $config) {
                    $target = explode('.', (string) ($config['foreignKey'] ?? ''), 2)[0] ?: (string) ($config['relation']['table'] ?? '');

                    // The tax class's list is TaxClassReferences', with its
                    // own completeness test (TaxClassReferencesTest).
                    if (!isset(CatalogReferences::REFERENCES[$target])) {
                        continue;
                    }

                    // Child rows go to the recycle bin along with it (ctable).
                    if ('pid' === $field && $target === ($definition['config']['ptable'] ?? null)) {
                        continue;
                    }

                    if (\in_array($table.'.'.$field, CatalogReferences::NOT_COUNTED, true)) {
                        continue;
                    }

                    $found[$target][] = $table.'.'.$field;
                }
            }
        }

        $expected = [];

        foreach (CatalogReferences::REFERENCES as $target => $references) {
            $linked = array_values(array_diff(array_keys($references), CatalogReferences::WITHOUT_DCA_LINK));

            // A table only referenced without a DCA link has nothing to find.
            if ([] !== $linked) {
                sort($linked);
                $expected[$target] = $linked;
            }
        }

        foreach ($found as &$references) {
            $references = array_values(array_unique($references));
            sort($references);
        }

        ksort($expected);
        ksort($found);

        self::assertSame($expected, $found, 'CatalogReferences::REFERENCES and the DCAs disagree. A reference missing from the constant is one the delete guard does not protect.');
    }

    private function references(): CatalogReferences
    {
        return new CatalogReferences($this->connection);
    }
}
