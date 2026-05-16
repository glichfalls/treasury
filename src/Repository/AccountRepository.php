<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Account>
 */
class AccountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Account::class);
    }

    /** @return Account[] */
    public function findByOwner(User $owner): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.owner = :owner')
            ->setParameter('owner', $owner->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->orderBy('a.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneOwnedBy(string $id, User $owner): ?Account
    {
        try {
            $uuid = \Symfony\Component\Uid\Uuid::fromString($id);
        } catch (\InvalidArgumentException) {
            return null;
        }
        return $this->createQueryBuilder('a')
            ->andWhere('a.id = :id AND a.owner = :owner')
            ->setParameter('id', $uuid, \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->setParameter('owner', $owner->getId(), \Symfony\Bridge\Doctrine\Types\UuidType::NAME)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Which of the supplied accounts already have an opening-balance row?
     * Used to hide the "Set starting balance" button after it's been used —
     * the flow is one-shot per account.
     *
     * @param \Symfony\Component\Uid\Uuid[] $accountIds
     * @return array<string, bool>  account_id (rfc4122) => has opening balance
     */
    public function hasOpeningBalanceMap(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $binIds = array_map(fn(\Symfony\Component\Uid\Uuid $u) => $u->toBinary(), $accountIds);
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            "SELECT DISTINCT account_id FROM transactions
             WHERE account_id IN (?) AND type = 'opening_balance'",
            [$binIds],
            [\Doctrine\DBAL\ArrayParameterType::BINARY],
        );
        $out = [];
        foreach ($rows as $r) {
            $out[\Symfony\Component\Uid\Uuid::fromBinary($r['account_id'])->toRfc4122()] = true;
        }
        return $out;
    }

    /**
     * @param \Symfony\Component\Uid\Uuid[] $accountIds
     * @return array<string, string>  account_id (rfc4122) => sum of amount_minor as string
     */
    public function sumBalancesMinor(array $accountIds): array
    {
        if ($accountIds === []) {
            return [];
        }
        $binIds = array_map(fn(\Symfony\Component\Uid\Uuid $u) => $u->toBinary(), $accountIds);

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT account_id, COALESCE(SUM(amount_minor), 0) AS bal FROM transactions WHERE account_id IN (?) GROUP BY account_id',
            [$binIds],
            [\Doctrine\DBAL\ArrayParameterType::BINARY]
        );

        $out = [];
        foreach ($rows as $r) {
            $out[\Symfony\Component\Uid\Uuid::fromBinary($r['account_id'])->toRfc4122()] = (string) $r['bal'];
        }
        return $out;
    }
}
