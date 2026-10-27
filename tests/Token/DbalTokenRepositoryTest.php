<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Token;

use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Token\DbalTokenRepository;
use Webwerkwien\ContaoConshoBundle\Token\TokenPurpose;
use Webwerkwien\ContaoConshoBundle\Tests\Dca\DcaSchema;

/**
 * C-10: whether a token came by mail survives the trip through the database.
 */
class DbalTokenRepositoryTest extends TestCase
{
    public function testDieMarkierungKommtWiederHeraus(): void
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        DcaSchema::create($connection, 'tl_consho_order_token');

        $repository = new DbalTokenRepository($connection);
        $repository->store(7, TokenPurpose::ViewOrder, 'aaa', 1, null, true);
        $repository->store(7, TokenPurpose::ViewOrder, 'bbb', 1, null);

        self::assertTrue($repository->findByHash('aaa', TokenPurpose::ViewOrder)['confirmsEmail'] ?? null);
        self::assertFalse($repository->findByHash('bbb', TokenPurpose::ViewOrder)['confirmsEmail'] ?? null);
    }
}
