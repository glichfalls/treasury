<?php

namespace App\Repository;

use App\Entity\Asset;
use App\Entity\AssetNewsSource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @extends ServiceEntityRepository<AssetNewsSource>
 */
class AssetNewsSourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AssetNewsSource::class);
    }

    /**
     * Enabled sources for an asset, oldest first (stable display order).
     *
     * @return AssetNewsSource[]
     */
    public function enabledForAsset(Asset $asset): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.asset = :asset')
            ->andWhere('s.enabled = true')
            ->setParameter('asset', $asset->getId(), UuidType::NAME)
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All sources for an asset (enabled or not), for the admin listing.
     *
     * @return AssetNewsSource[]
     */
    public function forAsset(Asset $asset): array
    {
        return $this->createQueryBuilder('s')
            ->where('s.asset = :asset')
            ->setParameter('asset', $asset->getId(), UuidType::NAME)
            ->orderBy('s.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
