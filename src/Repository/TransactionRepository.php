<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Transaction;
use App\Entity\TransactionCategory;
use App\Entity\TransactionType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    /**
     * Filtered, paginated transactions for an account.
     *
     * Pillar 3a accounts hide trade_buy/trade_sell rows (they're auto-generated
     * from contributions and clutter the list) — the count reflects that, so
     * pagination matches what the user sees.
     *
     * @return array{items: Transaction[], total: int}
     */
    public function findPage(
        Account $account,
        int $page = 1,
        int $pageSize = 25,
        ?TransactionType $type = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $to = null,
        ?string $q = null,
        ?TransactionCategory $category = null,
    ): array {
        $page = max(1, $page);
        $pageSize = max(1, min(200, $pageSize));

        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.account = :account')
            ->setParameter('account', $account->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->orderBy('t.occurredAt', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        if ($account->getType() === AccountType::Pillar3a) {
            $qb->andWhere('t.type NOT IN (:hidden)')
               ->setParameter('hidden', [TransactionType::TradeBuy, TransactionType::TradeSell]);
        }

        if ($type !== null) {
            $qb->andWhere('t.type = :type')->setParameter('type', $type);
        }
        if ($category !== null) {
            $qb->andWhere('t.category = :category')->setParameter('category', $category);
        }
        if ($from !== null) {
            $qb->andWhere('t.occurredAt >= :from')->setParameter('from', $from);
        }
        if ($to !== null) {
            $qb->andWhere('t.occurredAt <= :to')->setParameter('to', $to);
        }
        if ($q !== null && $q !== '') {
            $qb->andWhere('LOWER(t.description) LIKE :q OR t.assetIsin = :qExact')
               ->setParameter('q', '%' . strtolower($q) . '%')
               ->setParameter('qExact', strtoupper($q));
        }

        $qb->setFirstResult(($page - 1) * $pageSize)->setMaxResults($pageSize);

        $paginator = new Paginator($qb->getQuery(), fetchJoinCollection: false);
        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => count($paginator),
        ];
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
