<?php

namespace App\Backup;

use App\Entity\Account;
use App\Entity\AccountAllocation;
use App\Entity\AccountType;
use App\Entity\Transaction;
use App\Entity\TransactionSource;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Repository\AccountRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lossless backup/restore of an account, its allocations, and its transactions.
 *
 * Assets and prices are NOT included — transactions reference assets by ISIN (a stable
 * external identifier), so the target instance just needs the same asset catalog. FX
 * rates and prices are global and rebuilt by the price fetcher.
 */
final class AccountBackup
{
    public const VERSION = 1;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AccountRepository $accounts,
    ) {}

    public function exportForUser(User $user): array
    {
        return [
            'version' => self::VERSION,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'accounts' => array_map(
                fn(Account $a) => $this->serializeAccount($a),
                $this->accounts->findByOwner($user),
            ),
        ];
    }

    public function exportOne(Account $account): array
    {
        return [
            'version' => self::VERSION,
            'exportedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'accounts' => [$this->serializeAccount($account)],
        ];
    }

    public function import(array $payload, User $user, ImportMode $mode): ImportResult
    {
        $version = $payload['version'] ?? null;
        if ($version !== self::VERSION) {
            throw new \InvalidArgumentException(sprintf('Unsupported backup version: %s (expected %d)', json_encode($version), self::VERSION));
        }
        $accountsPayload = $payload['accounts'] ?? null;
        if (!is_array($accountsPayload)) {
            throw new \InvalidArgumentException('Missing or invalid "accounts" array');
        }

        $imported = 0;
        $skipped = 0;
        $errors = [];

        $this->em->beginTransaction();
        try {
            foreach ($accountsPayload as $i => $accountData) {
                try {
                    $result = $this->importAccount($accountData, $user, $mode);
                    if ($result === 'imported') {
                        $imported++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    $errors[] = sprintf('account[%d] (%s): %s', $i, $accountData['name'] ?? '?', $e->getMessage());
                }
            }
            $this->em->flush();
            $this->em->commit();
        } catch (\Throwable $e) {
            $this->em->rollback();
            throw $e;
        }

        return new ImportResult($imported, $skipped, $errors);
    }

    private function serializeAccount(Account $a): array
    {
        $allocations = $this->em->getRepository(AccountAllocation::class)
            ->findBy(['account' => $a], ['effectiveFrom' => 'ASC']);
        $transactions = $this->em->getRepository(Transaction::class)
            ->findBy(['account' => $a], ['occurredAt' => 'ASC']);

        return [
            'id' => $a->getId()->toRfc4122(),
            'name' => $a->getName(),
            'institution' => $a->getInstitution(),
            'type' => $a->getType()->value,
            'currency' => $a->getCurrency(),
            'createdAt' => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'allocations' => array_map(fn(AccountAllocation $al) => [
                'id' => $al->getId()->toRfc4122(),
                'assetIsin' => $al->getAssetIsin(),
                'percentBasisPoints' => $al->getPercentBasisPoints(),
                'effectiveFrom' => $al->getEffectiveFrom()->format('Y-m-d'),
            ], $allocations),
            'transactions' => array_map(fn(Transaction $t) => [
                'id' => $t->getId()->toRfc4122(),
                'occurredAt' => $t->getOccurredAt()->format('Y-m-d'),
                'amountMinor' => $t->getAmountMinor(),
                'currency' => $t->getCurrency(),
                'description' => $t->getDescription(),
                'type' => $t->getType()->value,
                'source' => $t->getSource()->value,
                'externalRef' => $t->getExternalRef(),
                'assetIsin' => $t->getAssetIsin(),
                'assetQuantity' => $t->getAssetQuantity(),
            ], $transactions),
        ];
    }

    /** @return 'imported'|'skipped' */
    private function importAccount(array $data, User $user, ImportMode $mode): string
    {
        $accountId = $data['id'] ?? null;
        if (!is_string($accountId)) {
            throw new \InvalidArgumentException('Account id missing');
        }
        $uuid = Uuid::fromString($accountId);

        $existing = $this->em->find(Account::class, $uuid);
        if ($existing !== null) {
            if ($existing->getOwner()->getId()->toRfc4122() !== $user->getId()->toRfc4122()) {
                throw new \RuntimeException('Account exists but belongs to a different user');
            }
            if ($mode === ImportMode::Skip) {
                return 'skipped';
            }
            // Replace: cascade-removes transactions; allocations removed explicitly.
            foreach ($this->em->getRepository(AccountAllocation::class)->findBy(['account' => $existing]) as $al) {
                $this->em->remove($al);
            }
            $this->em->remove($existing);
            $this->em->flush();
        }

        $type = AccountType::tryFrom((string) ($data['type'] ?? ''));
        if ($type === null) {
            throw new \InvalidArgumentException('Invalid account type: ' . ($data['type'] ?? 'null'));
        }
        $currency = strtoupper(trim((string) ($data['currency'] ?? '')));
        if (!preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new \InvalidArgumentException('Invalid currency: ' . $currency);
        }

        $account = new Account();
        $this->forceUuid($account, $uuid);
        $this->forceCreatedAt($account, $this->parseDateTime($data['createdAt'] ?? null));
        $account->setOwner($user);
        $account->setName((string) ($data['name'] ?? ''));
        $account->setInstitution($data['institution'] ?? null);
        $account->setType($type);
        $account->setCurrency($currency);
        $this->em->persist($account);

        foreach ($data['allocations'] ?? [] as $al) {
            $alloc = new AccountAllocation();
            $this->forceUuid($alloc, Uuid::fromString((string) $al['id']));
            $alloc->setAccount($account);
            $alloc->setAssetIsin((string) $al['assetIsin']);
            $alloc->setPercentBasisPoints((int) $al['percentBasisPoints']);
            $alloc->setEffectiveFrom(new \DateTimeImmutable($al['effectiveFrom']));
            $this->em->persist($alloc);
        }

        foreach ($data['transactions'] ?? [] as $t) {
            $tx = new Transaction();
            $this->forceUuid($tx, Uuid::fromString((string) $t['id']));
            $tx->setAccount($account);
            $tx->setOccurredAt(new \DateTimeImmutable($t['occurredAt']));
            $tx->setAmountMinor((string) $t['amountMinor']);
            $tx->setCurrency((string) $t['currency']);
            $tx->setDescription($t['description'] ?? null);
            $tx->setType(TransactionType::from((string) $t['type']));
            $tx->setSource(TransactionSource::from((string) $t['source']));
            $tx->setExternalRef($t['externalRef'] ?? null);
            $tx->setAssetIsin($t['assetIsin'] ?? null);
            $tx->setAssetQuantity($t['assetQuantity'] ?? null);
            $this->em->persist($tx);
        }

        return 'imported';
    }

    private function parseDateTime(?string $s): \DateTimeImmutable
    {
        if ($s === null || $s === '') {
            return new \DateTimeImmutable();
        }
        return new \DateTimeImmutable($s);
    }

    /**
     * Overwrite the constructor-generated UUID with the one from the payload so the
     * backup round-trips byte-identical IDs. Reflection is used because entities
     * intentionally don't expose a setId() — IDs are normally write-once.
     */
    private function forceUuid(object $entity, Uuid $id): void
    {
        $r = new \ReflectionProperty($entity, 'id');
        $r->setValue($entity, $id);
    }

    private function forceCreatedAt(Account $account, \DateTimeImmutable $createdAt): void
    {
        $r = new \ReflectionProperty($account, 'createdAt');
        $r->setValue($account, $createdAt);
    }
}
