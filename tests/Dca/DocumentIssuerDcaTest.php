<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Dca;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use PHPUnit\Framework\TestCase;

/**
 * Documents without a Consho number (block R, R16) next to the unique key
 * shop,type,documentNumber.
 */
class DocumentIssuerDcaTest extends TestCase
{
    public function testMehrereBelegeOhneNummerVertragenSichMitDemEindeutigenSchluessel(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_document');

        $connection->insert('tl_consho_document', ['pid' => 1, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'off']);
        $connection->insert('tl_consho_document', ['pid' => 2, 'shop' => 1, 'type' => 'invoice', 'issuer' => 'off']);

        self::assertSame(2, (int) $connection->fetchOne("SELECT COUNT(*) FROM tl_consho_document WHERE documentNumber IS NULL AND issuer = 'off'"));
    }

    public function testEineConshoNummerBleibtJeShopUndArtEindeutig(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_document');

        $connection->insert('tl_consho_document', ['pid' => 1, 'shop' => 1, 'type' => 'invoice', 'documentNumber' => 'RE-1']);

        $this->expectException(UniqueConstraintViolationException::class);
        $connection->insert('tl_consho_document', ['pid' => 2, 'shop' => 1, 'type' => 'invoice', 'documentNumber' => 'RE-1']);
    }

    public function testBestehendeBelegeGeltenAlsVonConshoAusgestellt(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_document');
        DcaSchema::create($connection, 'tl_consho_shop');

        $connection->insert('tl_consho_document', ['pid' => 1, 'shop' => 1, 'type' => 'invoice', 'documentNumber' => 'RE-1']);
        $connection->insert('tl_consho_shop', ['title' => 'Shop']);

        self::assertSame(['consho', '', ''], $connection->fetchNumeric('SELECT issuer, externalId, externalState FROM tl_consho_document'));
        self::assertSame('consho', $connection->fetchOne('SELECT invoicing FROM tl_consho_shop'));
    }
}
