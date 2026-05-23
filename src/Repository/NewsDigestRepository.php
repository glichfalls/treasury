<?php

namespace App\Repository;

use App\Entity\NewsDigest;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<NewsDigest>
 */
class NewsDigestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, NewsDigest::class);
    }

    public function latestForOwner(User $owner): ?NewsDigest
    {
        return $this->findOneBy(['owner' => $owner], ['generatedAt' => 'DESC']);
    }
}
