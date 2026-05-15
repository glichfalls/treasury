<?php

namespace App\Recurring;

use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\TransactionSource;
use App\Repository\RecurringTransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Walks every active RecurringTransaction rule and materializes any due occurrences
 * as concrete Transaction rows. Idempotent — each rule tracks lastGeneratedAt, so a
 * second run on the same day is a no-op.
 *
 * Safe to call multiple times. Runs daily via the Symfony Scheduler; admins can
 * also trigger per-rule materialization manually via the API endpoint.
 */
final class RecurringMaterializer
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RecurringTransactionRepository $rules,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Materialize a single rule up to $upTo (default: today). Returns the number
     * of Transactions created.
     */
    public function materializeOne(RecurringTransaction $rule, ?\DateTimeImmutable $upTo = null): int
    {
        $upTo ??= new \DateTimeImmutable('today');
        $upTo = $upTo->setTime(0, 0);

        if (!$rule->isActive()) {
            return 0;
        }

        $created = 0;
        $cursor = $rule->getLastGeneratedAt()
            ?? $rule->getStartsAt()->modify('-1 day');

        while (true) {
            $next = $rule->nextOccurrenceAfter($cursor);
            if ($next === null || $next > $upTo) {
                break;
            }

            $tx = new Transaction();
            $tx->setAccount($rule->getAccount());
            $tx->setOccurredAt($next);
            $tx->setAmountMinor($rule->getAmountMinor());
            $tx->setCurrency($rule->getCurrency());
            $tx->setDescription($rule->getDescription());
            $tx->setType($rule->getType());
            $tx->setSource(TransactionSource::Recurring);
            $tx->setCategory($rule->getCategory());
            // External-ref tag lets us spot rule-generated rows in queries.
            $tx->setExternalRef('recurring:' . $rule->getId()->toRfc4122() . ':' . $next->format('Y-m-d'));

            $this->em->persist($tx);
            $rule->setLastGeneratedAt($next);
            $created++;
            $cursor = $next;

            // Safety valve: a rule that's been off for years could otherwise spew
            // hundreds of rows at once. Stop after 365 occurrences per call.
            if ($created >= 365) {
                $this->logger->warning('Recurring rule hit 365-occurrence cap in one run', [
                    'rule' => $rule->getId()->toRfc4122(),
                ]);
                break;
            }
        }

        if ($created > 0) {
            $this->em->flush();
        }
        return $created;
    }

    /** Materialize every active rule. Returns total transactions created. */
    public function materializeAll(?\DateTimeImmutable $upTo = null): int
    {
        $total = 0;
        foreach ($this->rules->findAllActive() as $rule) {
            $total += $this->materializeOne($rule, $upTo);
        }
        return $total;
    }
}
