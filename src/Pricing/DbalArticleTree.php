<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Pricing;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final class DbalArticleTree implements ArticleTreeInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function articlesOf(array $productIds): array
    {
        $articles = [];

        // Chunks keep a rebuild over the whole catalogue from building one IN
        // list with every product id in it.
        foreach (array_chunk(array_values(array_unique($productIds)), 1000) as $chunk) {
            $rows = $this->connection->fetchAllAssociative(
                'SELECT id, pid FROM tl_consho_product WHERE id IN (:ids) OR pid IN (:ids)',
                ['ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            );

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $pid = (int) $row['pid'];

                // Variants are never nested (VariantResolver), so the parent of
                // a variant is always a main product.
                $articles[$id] = $pid > 0 ? $pid : $id;
            }
        }

        return $articles;
    }
}
