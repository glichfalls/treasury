<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\RecurringTransaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RecurringTransaction>
 */
class RecurringTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecurringTransaction::class);
    }

    /** @return RecurringTransaction[] */
    public function findByAccount(Account $account): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.account = :a')
            ->setParameter('a', $account->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->orderBy('r.active', 'DESC')
            ->addOrderBy('r.description', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /** @return RecurringTransaction[] */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.active = true')
            ->getQuery()
            ->getResult();
    }
}
