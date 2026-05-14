<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Transaction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /** @return Transaction[] */
    public function findByAccount(Account $account, int $limit = 200): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.account = :account')
            ->setParameter('account', $account->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->orderBy('t.occurredAt', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /** @return array<string, true> Map of externalRef => true for fast membership tests. */
    public function findExternalRefsForAccount(Account $account): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.externalRef')
            ->andWhere('t.account = :account AND t.externalRef IS NOT NULL')
            ->setParameter('account', $account->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $out[$r['externalRef']] = true;
        }
        return $out;
    }
}
