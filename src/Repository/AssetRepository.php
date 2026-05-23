<?php

namespace App\Repository;

use App\Entity\Asset;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Asset>
 */
class AssetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Asset::class);
    }

    public function findByIsin(string $isin): ?Asset
    {
        return $this->findOneBy(['isin' => strtoupper($isin)]);
    }

    /**
     * Assets eligible for news aggregation: currently held (nonzero quantity in
     * some account), news not muted, and carrying a ticker (so commodity coins
     * and cash are excluded). News is fetched per-asset by ticker/market topic.
     *
     * @return Asset[]
     */
    public function findActiveForNews(): array
    {
        $sql = <<<'SQL'
            SELECT a.id
            FROM assets a
            INNER JOIN transactions t ON t.asset_isin = a.isin
            WHERE a.news_enabled = 1 AND a.ticker IS NOT NULL
            GROUP BY a.id, a.isin
            HAVING SUM(t.asset_quantity) <> 0
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative($sql);
        if ($rows === []) {
            return [];
        }
        $uuids = array_map(
            fn($r) => \Symfony\Component\Uid\Uuid::fromBinary($r['id']),
            $rows,
        );
        return $this->findBy(['id' => $uuids]);
    }

    /** @return Asset[] */
    public function findHeldByAccount(\Symfony\Component\Uid\Uuid $accountId): array
    {
        $sql = <<<'SQL'
            SELECT a.id
            FROM assets a
            INNER JOIN transactions t ON t.asset_isin = a.isin
            WHERE t.account_id = :account_id AND t.asset_quantity IS NOT NULL
            GROUP BY a.id, a.isin
            HAVING SUM(t.asset_quantity) <> 0
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            ['account_id' => $accountId->toBinary()],
        );
        if ($rows === []) {
            return [];
        }
        $uuids = array_map(
            fn($r) => \Symfony\Component\Uid\Uuid::fromBinary($r['id']),
            $rows,
        );
        return $this->findBy(['id' => $uuids]);
    }
}
