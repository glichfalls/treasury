<?php

namespace App\Repository;

use App\Entity\RegistrationCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RegistrationCode>
 */
class RegistrationCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RegistrationCode::class);
    }

    public function findByCode(string $code): ?RegistrationCode
    {
        return $this->findOneBy(['code' => $code]);
    }

    /** @return RegistrationCode[] */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
