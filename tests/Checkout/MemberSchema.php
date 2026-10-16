<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Checkout;

use Doctrine\DBAL\Connection;

/**
 * Das SQLite-Testschema für `tl_member`, soweit der Checkout es liest.
 *
 * Gebaut nach Contaos eigener `tl_member.php` und nach `contao/dca/tl_member.php`
 * dieses Bundles — mit denselben Spalten und denselben Typnamen:
 *
 * - `country varchar(6)`, nicht `(2)`. Contao lässt Untergliederungen wie
 *   `GB-ENG` zu, Consho rechnet mit ISO 3166-1 alpha-2.
 *
 *   ⚠️ **Die Länge trägt hier allerdings nichts.** SQLite kennt keine
 *   Spaltenbreite — `varchar(2)` nähme `GB-ENG` genauso an. Der Wert dieser
 *   Zeile ist, dass sie neben dem echten Schema steht und nicht von ihm
 *   abweicht; **abgesichert ist der Fall durch den Test**, der `GB-ENG`
 *   einträgt und ein leeres Land erwartet, nicht durch die Zahl in Klammern.
 *   (Im Review vom 2026-09-23 als Dekoration erkannt — ein Kommentar, der
 *   eine Zusicherung behauptet, die der Aufbau nicht leisten kann, ist
 *   genauso irreführend wie ein Test, der nichts prüft.)
 * - `gender varchar(32)` ist da, obwohl es **nicht** übernommen wird — damit
 *   ein Test festhalten kann, dass es liegen bleibt.
 *
 * Nur die Spalten, die `MemberCheckoutDefaults` abfragt: Eine Nachbildung von
 * `tl_member` mit allen sechzig Feldern wäre eine zweite Wahrheit, die
 * auseinanderläuft.
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
