<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Command;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Command\KnownShops;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * Nobody calling the commands should have to guess input. An unknown shop
 * ID is answered with the existing ones.
 */
class KnownShopsTest extends TestCase
{
    public function testDieShopsInIdReihenfolgeMitLesbaremTitel(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_shop');
        $connection->insert('tl_consho_shop', ['id' => 4, 'title' => 'I7 Shop']);
        $connection->insert('tl_consho_shop', ['id' => 2, 'title' => 'Testshop 2 &#40;DE&#41;']);

        self::assertSame([2 => 'Testshop 2 &#40;DE&#41;', 4 => 'I7 Shop'], KnownShops::of($connection));
        self::assertSame('Shop configurations: 2 (Testshop 2 (DE)), 4 (I7 Shop).', KnownShops::sentence(KnownShops::of($connection)));
    }

    public function testOhneShop(): void
    {
        self::assertSame('There is none yet.', KnownShops::sentence([]));
    }
}
