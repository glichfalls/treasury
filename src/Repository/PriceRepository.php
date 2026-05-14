<?php

namespace App\Repository;

use App\Entity\Asset;
use App\Entity\Price;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Price>
 */
class PriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Price::class);
    }

    public function findLatest(Asset $asset): ?Price
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.asset = :asset')
            ->setParameter('asset', $asset)
            ->orderBy('p.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param \Symfony\Component\Uid\Uuid[] $assetIds
     * @return array<string, Price>  asset_id (rfc4122) => latest Price
     */
    public function findLatestByAssetIds(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        $binIds = array_map(fn(\Symfony\Component\Uid\Uuid $u) => $u->toBinary(), $assetIds);

        $sql = <<<'SQL'
            SELECT p.id
            FROM prices p
            INNER JOIN (
                SELECT asset_id, MAX(occurred_at) AS max_date
                FROM prices
                WHERE asset_id IN (?)
                GROUP BY asset_id
            ) latest ON latest.asset_id = p.asset_id AND latest.max_date = p.occurred_at
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            [$binIds],
            [\Doctrine\DBAL\ArrayParameterType::BINARY],
        );
        if ($rows === []) {
            return [];
        }
        $uuids = array_map(fn($r) => \Symfony\Component\Uid\Uuid::fromBinary($r['id']), $rows);

        $prices = $this->findBy(['id' => $uuids]);
        $out = [];
        foreach ($prices as $p) {
            $out[$p->getAsset()->getId()->toRfc4122()] = $p;
        }
        return $out;
    }
}
