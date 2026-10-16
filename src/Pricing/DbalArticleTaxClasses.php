<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\Connection;

final class DbalArticleTaxClasses implements ArticleTaxClassesInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function taxClassOf(int $articleId): int
    {
        return (int) $this->connection->fetchOne('SELECT taxClass FROM tl_consho_product WHERE id = ?', [$articleId]);
    }
}
