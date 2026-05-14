<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountAllocation;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountAllocation>
 */
class AccountAllocationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountAllocation::class);
    }

    /**
     * All rules belonging to the rule batch with the most recent effectiveFrom that is
     * still ≤ $asOf. Returns [] if no allocation was set up by that date.
     *
     * @return AccountAllocation[]
     */
    public function findEffective(Account $account, ?\DateTimeImmutable $asOf = null): array
    {
        $asOf ??= new \DateTimeImmutable('today');

        $latest = $this->createQueryBuilder('a')
            ->select('MAX(a.effectiveFrom) AS d')
            ->andWhere('a.account = :account AND a.effectiveFrom <= :asOf')
            ->setParameter('account', $account->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->setParameter('asOf', $asOf->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();

        if ($latest === null) {
            return [];
        }

        return $this->createQueryBuilder('a')
            ->andWhere('a.account = :account AND a.effectiveFrom = :ef')
            ->setParameter('account', $account->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->setParameter('ef', $latest)
            ->orderBy('a.percentBasisPoints', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Group all the account's rules by effectiveFrom, latest first. Useful for showing
     * strategy history.
     *
     * @return array<string, AccountAllocation[]>  keyed by 'YYYY-MM-DD'
     */
    public function findAllVersions(Account $account): array
    {
        $rows = $this->createQueryBuilder('a')
            ->andWhere('a.account = :account')
            ->setParameter('account', $account->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->orderBy('a.effectiveFrom', 'DESC')
            ->addOrderBy('a.percentBasisPoints', 'DESC')
            ->getQuery()
            ->getResult();

        $byDate = [];
        foreach ($rows as $r) {
            $byDate[$r->getEffectiveFrom()->format('Y-m-d')][] = $r;
        }
        return $byDate;
    }

    /** Backwards-compat alias — returns the currently-effective rules. */
    public function findByAccount(Account $account): array
    {
        return $this->findEffective($account);
    }
}
