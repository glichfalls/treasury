<?php

namespace App\Repository;

use App\Entity\PlanScenario;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PlanScenario>
 */
class PlanScenarioRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlanScenario::class);
    }

    /** @return PlanScenario[] */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.owner = :owner')
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->orderBy('s.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(string $id, User $owner): ?PlanScenario
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $this->createQueryBuilder('s')
            ->andWhere('s.id = :id AND s.owner = :owner')
            ->setParameter('id', $uuid, UuidType::NAME)
            ->setParameter('owner', $owner->getId(), UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
