<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Customer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Tests\Privacy\PrivacySchema;
use Webwerkwien\ContaoConshoBundle\Customer\Customer;
use Webwerkwien\ContaoConshoBundle\Customer\CustomerDirectory;

/**
 * D-4, K2 — „Kunde ergibt sich aus den Bestellungen" (Michael, 2026-09-25):
 * Mitglieder mit Bestellung, Gäste nach Adresse, dazu die Basisgruppe für
 * Konten ohne Bestellung. Ein Gast mit der Adresse eines Mitglieds desselben
 * Shops gehört zu diesem.
 */
final class CustomerDirectoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        // The shared D-5 schema: production column names (Fall 31).
        $this->connection = PrivacySchema::create();

        $this->connection->insert('tl_consho_shop', ['id' => 1, 'title' => 'Wien', 'customerGroup' => 3]);
        $this->connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Berlin', 'customerGroup' => 0]);

        $this->connection->insert('tl_member', ['id' => 5, 'firstname' => 'Erika', 'lastname' => 'Konto', 'email' => 'Erika@Example.org', 'groups' => serialize(['3'])]);
        $this->connection->insert('tl_member', ['id' => 6, 'firstname' => 'Nur', 'lastname' => 'Gruppe', 'email' => 'gruppe@example.org', 'groups' => serialize(['3'])]);
        $this->connection->insert('tl_member', ['id' => 7, 'firstname' => 'Intern', 'lastname' => 'Redaktion', 'email' => 'intern@example.org', 'groups' => serialize(['9'])]);
        $this->connection->insert('tl_member', ['id' => 8, 'firstname' => 'Dreizehn', 'lastname' => 'Andere', 'email' => 'x@example.org', 'groups' => serialize(['13'])]);

        // Erika: zwei Bestellungen im Konto, eine als Gast mit derselben Adresse (andere Schreibweise).
        $this->order(1, 1, 5, 'erika@example.org', 'Erika', 'Konto', 100);
        $this->order(2, 1, 5, 'erika@example.org', 'Erika', 'Konto', 300);
        $this->order(3, 1, 0, 'ERIKA@example.org', 'Erika', 'Gast', 200);

        // Ein Gast, zweimal, mit geändertem Namen — der jüngste gilt.
        $this->order(4, 1, 0, 'gast@example.org', 'Max', 'Alt', 150);
        $this->order(5, 1, 0, 'Gast@Example.org', 'Max', 'Neu', 250);

        // Derselbe Gast im anderen Shop ist dort ein eigener Kunde.
        $this->order(6, 2, 0, 'gast@example.org', 'Max', 'Berlin', 50);

        // Bestellung eines gelöschten Kontos: zählt als Gast.
        $this->order(7, 2, 99, 'weg@example.org', 'Weg', 'Gelöscht', 60);
    }

    /** Twig kodiert selbst; ein kodierter Titel erschiene als „&#40;". */
    public function testDerShopnameKommtDekodiert(): void
    {
        $this->connection->update('tl_consho_shop', ['title' => 'Wien &#40;AT&#41;'], ['id' => 1]);
        $directory = $this->directory();

        self::assertSame('Wien (AT)', $directory->shops(1)[0]['title']);
        self::assertSame('Wien (AT)', $directory->list(1)[0]->shopTitle);
    }

    public function testDieKundenEinesShops(): void
    {
        $customers = $this->directory()->list(1);

        self::assertSame(['m:5', 'g:gast@example.org', 'm:6'], array_map(static fn (Customer $c): string => $c->key, $customers), 'nach letzter Bestellung, Konten ohne Bestellung zuletzt');

        [$erika, $gast, $gruppe] = $customers;

        self::assertSame(5, $erika->memberId);
        self::assertSame('Erika Konto', $erika->name);
        self::assertSame('Erika@Example.org', $erika->email);
        self::assertSame(3, $erika->orders, 'die Gastbestellung mit ihrer Adresse gehört dazu');
        self::assertSame(300, $erika->lastOrderAt);
        self::assertTrue($erika->alsoGuest);

        self::assertNull($gast->memberId);
        self::assertSame('Max Neu', $gast->name);
        self::assertSame(2, $gast->orders, 'Adresse ohne Groß-/Kleinschreibung');

        self::assertSame(0, $gruppe->orders);
        self::assertNull($gruppe->lastOrderAt);

        foreach ($customers as $customer) {
            self::assertNotSame(7, $customer->memberId, 'Mitglied einer anderen Gruppe ohne Bestellung ist kein Kunde');
            self::assertNotSame(8, $customer->memberId, 'Gruppe 13 ist nicht Gruppe 3');
        }
    }

    public function testAlleShopsUndDerGeloeschteKontoinhaber(): void
    {
        $customers = $this->directory()->list(null);

        $berlin = array_values(array_filter($customers, static fn (Customer $c): bool => 2 === $c->shopId));
        self::assertSame(['g:weg@example.org', 'g:gast@example.org'], array_map(static fn (Customer $c): string => $c->key, $berlin));
        self::assertSame('Berlin', $berlin[0]->shopTitle);
        self::assertCount(5, $customers);
    }

    public function testSuche(): void
    {
        self::assertSame(['m:5'], array_map(static fn (Customer $c): string => $c->key, $this->directory()->list(1, 'KONTO')));
        self::assertSame(['g:gast@example.org'], array_map(static fn (Customer $c): string => $c->key, $this->directory()->list(1, 'gast@')));
    }

    public function testDieBestellungenEinesKunden(): void
    {
        $directory = $this->directory();

        self::assertSame([2, 3, 1], array_column($directory->orders(1, 'm:5'), 'id'), 'Konto und Gast mit derselben Adresse, neueste zuerst');
        self::assertSame([5, 4], array_column($directory->orders(1, 'g:gast@example.org'), 'id'));
        self::assertSame([6], array_column($directory->orders(2, 'g:gast@example.org'), 'id'), 'nur der eine Shop');
        self::assertSame([], $directory->orders(1, 'm:6'));
        self::assertSame([], $directory->orders(1, 'unsinn'));
    }

    public function testEinKundeEinzeln(): void
    {
        self::assertSame('Erika Konto', $this->directory()->find(1, 'm:5')?->name);
        self::assertNull($this->directory()->find(1, 'm:7'));
    }

    private function order(int $id, int $shop, int $member, string $email, string $firstname, string $lastname, int $createdAt): void
    {
        $this->connection->insert('tl_consho_order', ['id' => $id, 'shop' => $shop, 'member' => $member, 'orderNumber' => 'WEB-'.$id, 'email' => $email, 'firstname' => $firstname, 'lastname' => $lastname, 'createdAt' => $createdAt]);
    }

    private function directory(): CustomerDirectory
    {
        return new CustomerDirectory($this->connection);
    }
}
