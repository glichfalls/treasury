<?php

namespace App\Repository;

use App\Entity\FxRate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FxRate>
 */
class FxRateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FxRate::class);
    }

    public function findLatest(string $from, string $to): ?FxRate
    {
        $from = strtoupper($from);
        $to = strtoupper($to);
        if ($from === $to) {
            return null;
        }
        return $this->createQueryBuilder('r')
            ->andWhere('r.fromCurrency = :from AND r.toCurrency = :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
