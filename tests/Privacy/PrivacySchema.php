<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Privacy;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;
use Webwerkwien\ContaoConshoBundle\Tests\Withdrawal\WithdrawalSchema;

/**
 * One schema for the D-5 tests, with the production column names — every
 * column the anonymisation touches, under the name the DCA gives it
 * (Prüfungsnotiz, Fall 31: a test schema that avoids the real names cannot
 * find what the real names break).
 */
final class PrivacySchema
{
    public static function create(): Connection
    {
        $c = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);

        DcaSchema::create($c, 'tl_consho_shop');
        // Contao's table, hand-written: the bundle's DCA only adds the consho_* fields.
        $c->executeStatement("CREATE TABLE tl_member (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, firstname TEXT NOT NULL DEFAULT '', lastname TEXT NOT NULL DEFAULT '', email TEXT NOT NULL DEFAULT '', groups BLOB NULL,
            consho_shippingCompany TEXT NOT NULL DEFAULT '', consho_shippingSalutation TEXT NOT NULL DEFAULT '', consho_shippingFirstname TEXT NOT NULL DEFAULT '', consho_shippingLastname TEXT NOT NULL DEFAULT '',
            consho_shippingStreet TEXT NOT NULL DEFAULT '', consho_shippingPostal TEXT NOT NULL DEFAULT '', consho_shippingCity TEXT NOT NULL DEFAULT '', consho_shippingState TEXT NOT NULL DEFAULT '', consho_shippingCountry TEXT NOT NULL DEFAULT '',
            consho_vatId TEXT NOT NULL DEFAULT '', consho_vatIdStatus TEXT NOT NULL DEFAULT '', consho_vatIdCheckedAt INTEGER NULL, consho_vatIdRequestIdentifier TEXT NOT NULL DEFAULT '',
            consho_confirmedEmail TEXT NOT NULL DEFAULT '', consho_confirmedEmailAt INTEGER NULL)");

        // Every Consho table from its DCA, so a personal column added there
        // reaches the anonymisation tests without a copy to update (Fall 31/57).
        foreach ([
            'tl_consho_order', 'tl_consho_order_item', 'tl_consho_document', 'tl_consho_payment', 'tl_consho_delivery',
            'tl_consho_complaint', 'tl_consho_order_token', 'tl_consho_cart', 'tl_consho_cart_item', 'tl_consho_payment_attempt',
        ] as $table) {
            DcaSchema::create($c, $table);
        }
        $c->executeStatement("CREATE TABLE tl_version (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, tstamp INTEGER NOT NULL DEFAULT 0, version INTEGER NOT NULL DEFAULT 1, fromTable TEXT NOT NULL DEFAULT '', userid INTEGER NOT NULL DEFAULT 0, username TEXT NULL, description TEXT NULL, editUrl TEXT NULL, active INTEGER NOT NULL DEFAULT 0, data BLOB NULL)");
        $c->executeStatement("CREATE TABLE tl_opt_in (id INTEGER PRIMARY KEY, tstamp INTEGER NOT NULL DEFAULT 0, token TEXT NOT NULL DEFAULT '', createdOn INTEGER NOT NULL DEFAULT 0, confirmedOn INTEGER NOT NULL DEFAULT 0, removeOn INTEGER NOT NULL DEFAULT 0, invalidatedThrough TEXT NULL, email TEXT NOT NULL DEFAULT '', emailSubject TEXT NULL, emailText TEXT NULL)");
        $c->executeStatement("CREATE TABLE tl_opt_in_related (id INTEGER PRIMARY KEY, pid INTEGER NOT NULL DEFAULT 0, relTable TEXT NOT NULL DEFAULT '', relId INTEGER NOT NULL DEFAULT 0)");

        WithdrawalSchema::create($c);

        // Abo-DSGVO: built from the DCAs, not copied (Fall 31). The
        // subscription and the entitlement come with WithdrawalSchema.
        // Abo-4b: the payment method a mandate charges through (P98).
        // Block G (G12): the stock notices of a member.
        // Block H (H-5): the codes redeemed with an order.
        foreach (['tl_consho_cancellation', 'tl_consho_download', 'tl_consho_key', 'tl_consho_payment_method', 'tl_consho_subscription_pause', 'tl_consho_stock_notice', 'tl_consho_product', 'tl_consho_cart_rule', 'tl_consho_cart_rule_redemption', 'tl_consho_voucher'] as $table) {
            DcaSchema::create($c, $table);
        }

        return $c;
    }
}
