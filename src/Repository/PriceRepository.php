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
            ->setParameter('asset', $asset->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->orderBy('p.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Latest two prices per asset (most recent + the one before it). Used to
     * compute day-over-day % change. Returns at most two entries per asset,
     * ordered most-recent first.
     *
     * @param \Symfony\Component\Uid\Uuid[] $assetIds
     * @return array<string, list<array{occurredAt: string, priceMinor: string, currency: string}>>
     *         keyed by asset_id (rfc4122)
     */
    public function findLatestTwoByAssetIds(array $assetIds): array
    {
        if ($assetIds === []) {
            return [];
        }
        $binIds = array_map(fn(\Symfony\Component\Uid\Uuid $u) => $u->toBinary(), $assetIds);

        // Window function: rank prices per asset by date desc, take the top 2.
        // Supported by MySQL 8+ and MariaDB 10.2+ (we target 10.6 in prod).
        $sql = <<<'SQL'
            SELECT asset_id, occurred_at, price_minor, currency
            FROM (
                SELECT asset_id, occurred_at, price_minor, currency,
                       ROW_NUMBER() OVER (PARTITION BY asset_id ORDER BY occurred_at DESC) AS rn
                FROM prices
                WHERE asset_id IN (?)
            ) ranked
            WHERE rn <= 2
            ORDER BY asset_id, occurred_at DESC
        SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            [$binIds],
            [\Doctrine\DBAL\ArrayParameterType::BINARY],
        );

        $out = [];
        foreach ($rows as $r) {
            $key = \Symfony\Component\Uid\Uuid::fromBinary($r['asset_id'])->toRfc4122();
            $out[$key][] = [
                'occurredAt' => $r['occurred_at'],
                'priceMinor' => (string) $r['price_minor'],
                'currency' => $r['currency'],
            ];
        }
        return $out;
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
