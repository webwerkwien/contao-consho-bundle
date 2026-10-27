<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Doctrine\DBAL\Connection;

/**
 * The SQLite test schema for `tl_member`, as far as the checkout reads it.
 *
 * Built after Contao's own `tl_member.php` and this bundle's
 * `contao/dca/tl_member.php` — with the same columns and the same type names:
 *
 * - `country varchar(6)`, not `(2)`. Contao allows subdivisions such as
 *   `GB-ENG`, Consho works with ISO 3166-1 alpha-2.
 *
 *   ⚠️ **The length carries nothing here, though.** SQLite has no column
 *   width — `varchar(2)` would accept `GB-ENG` just the same. The value of
 *   this line is that it sits next to the real schema and does not deviate
 *   from it; **the case is guarded by the test** that enters `GB-ENG` and
 *   expects an empty country, not by the number in brackets. (A comment
 *   that claims an assurance the setup cannot deliver is as misleading as a
 *   test that checks nothing.)
 * - `gender varchar(32)` is there although it is **not** taken over — so
 *   that a test can record that it is left alone.
 *
 * Only the columns `MemberCheckoutDefaults` queries: a replica of
 * `tl_member` with all sixty fields would be a second truth that drifts
 * apart.
 */
trait MemberSchema
{
    private function createMemberTable(Connection $connection): void
    {
        $connection->executeStatement(
            'CREATE TABLE tl_member (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                company TEXT NOT NULL DEFAULT "",
                gender TEXT NOT NULL DEFAULT "",
                firstname TEXT NOT NULL DEFAULT "",
                lastname TEXT NOT NULL DEFAULT "",
                street TEXT NOT NULL DEFAULT "",
                postal TEXT NOT NULL DEFAULT "",
                city TEXT NOT NULL DEFAULT "",
                state TEXT NOT NULL DEFAULT "",
                country TEXT NOT NULL DEFAULT "",
                phone TEXT NOT NULL DEFAULT "",
                email TEXT NOT NULL DEFAULT "",
                consho_shippingCompany TEXT NOT NULL DEFAULT "",
                consho_shippingSalutation TEXT NOT NULL DEFAULT "",
                consho_shippingFirstname TEXT NOT NULL DEFAULT "",
                consho_shippingLastname TEXT NOT NULL DEFAULT "",
                consho_shippingStreet TEXT NOT NULL DEFAULT "",
                consho_shippingPostal TEXT NOT NULL DEFAULT "",
                consho_shippingCity TEXT NOT NULL DEFAULT "",
                consho_shippingState TEXT NOT NULL DEFAULT "",
                consho_shippingCountry TEXT NOT NULL DEFAULT "",
                consho_vatId TEXT NOT NULL DEFAULT ""
            )',
        );
    }

    /**
     * @param array<string, string> $values
     */
    private function insertMember(Connection $connection, int $id, array $values = []): void
    {
        $connection->insert('tl_member', ['id' => $id] + $values);
    }
}
