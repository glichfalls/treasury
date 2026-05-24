<?php

namespace App\Repository;

use App\Entity\NewsItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<NewsItem>
 */
class NewsItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsItem::class);
    }

    /**
     * Of the given candidate content hashes, return those already stored for
     * this asset. Lets the fetcher skip re-inserting articles we've seen,
     * mirroring the idempotent re-import behaviour elsewhere.
     *
     * @param string[] $hashes
     * @return array<string, true>
     */
    public function existingHashesForAsset(Uuid $assetId, array $hashes): array
    {
        if ($hashes === []) {
            return [];
        }
        $rows = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT content_hash FROM news_items WHERE asset_id = ? AND content_hash IN (?)',
            [$assetId->toBinary(), $hashes],
            [ParameterType::BINARY, ArrayParameterType::STRING],
        );
        return array_fill_keys($rows, true);
    }

    /**
     * Items that still need AI classification (no sentiment yet) for the given
     * kinds. Oldest-first so a backlog drains in publish order.
     *
     * @param string[] $kinds
     * @param string[] $excludeSources Source keys to leave out (e.g. 'custom',
     *   which is classified lazily under its own per-source AI gate).
     * @return NewsItem[]
     */
    public function findUnclassified(array $kinds, int $limit = 50, array $excludeSources = []): array
    {
        $qb = $this->createQueryBuilder('n')
            ->where('n.sentiment IS NULL')
            ->andWhere('n.kind IN (:kinds)')
            ->setParameter('kinds', $kinds)
            ->orderBy('n.publishedAt', 'ASC')
            ->setMaxResults($limit);

        if ($excludeSources !== []) {
            $qb->andWhere('n.source NOT IN (:excludeSources)')
                ->setParameter('excludeSources', $excludeSources);
        }

        return $qb->getQuery()->getResult();
    }
}
