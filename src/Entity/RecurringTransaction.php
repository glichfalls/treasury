<?php

namespace App\Entity;

use App\Repository\RecurringTransactionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Template for transactions that repeat on a schedule (rent, salary, subscriptions).
 *
 * Materialization is idempotent: a daily scheduler walks each active rule and
 * generates any missing Transaction rows up to today. `lastGeneratedAt` tracks
 * the most recent occurrence already written so we never duplicate.
 */
#[ORM\Entity(repositoryClass: RecurringTransactionRepository::class)]
#[ORM\Table(name: 'recurring_transactions')]
class RecurringTransaction
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Account::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Account $account;

    #[ORM\Column(length: 120)]
    private string $description;

    #[ORM\Column(type: 'bigint')]
    private string $amountMinor;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(length: 16, enumType: TransactionType::class)]
    private TransactionType $type = TransactionType::Other;

    #[ORM\Column(length: 32, enumType: TransactionCategory::class, nullable: true)]
    private ?TransactionCategory $category = null;

    #[ORM\Column(length: 16, enumType: RecurringFrequency::class)]
    private RecurringFrequency $frequency;

    /** 1–31 for monthly/yearly. Days beyond month length clamp to the last valid day. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfMonth = null;

    /** 1 (Mon) – 7 (Sun) for weekly. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $dayOfWeek = null;

    /** 1–12 for yearly. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $monthOfYear = null;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    /** False = paused; the materializer skips it but the rule stays. */
    #[ORM\Column]
    private bool $active = true;

    /** Latest occurrence already materialized. Null until the first run. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastGeneratedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->id = Uuid::v7();
        $this->createdAt = new \DateTimeImmutable();
        $this->startsAt = new \DateTimeImmutable('today');
    }

    public function getId(): Uuid { return $this->id; }
    public function getAccount(): Account { return $this->account; }
    public function setAccount(Account $a): self { $this->account = $a; return $this; }
    public function getDescription(): string { return $this->description; }
    public function setDescription(string $d): self { $this->description = $d; return $this; }
    public function getAmountMinor(): string { return $this->amountMinor; }
    public function setAmountMinor(string|int $a): self { $this->amountMinor = (string) $a; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): self { $this->currency = strtoupper($c); return $this; }
    public function getType(): TransactionType { return $this->type; }
    public function setType(TransactionType $t): self { $this->type = $t; return $this; }
    public function getCategory(): ?TransactionCategory { return $this->category; }
    public function setCategory(?TransactionCategory $c): self { $this->category = $c; return $this; }
    public function getFrequency(): RecurringFrequency { return $this->frequency; }
    public function setFrequency(RecurringFrequency $f): self { $this->frequency = $f; return $this; }
    public function getDayOfMonth(): ?int { return $this->dayOfMonth; }
    public function setDayOfMonth(?int $d): self { $this->dayOfMonth = $d; return $this; }
    public function getDayOfWeek(): ?int { return $this->dayOfWeek; }
    public function setDayOfWeek(?int $d): self { $this->dayOfWeek = $d; return $this; }
    public function getMonthOfYear(): ?int { return $this->monthOfYear; }
    public function setMonthOfYear(?int $m): self { $this->monthOfYear = $m; return $this; }
    public function getStartsAt(): \DateTimeImmutable { return $this->startsAt; }
    public function setStartsAt(\DateTimeImmutable $s): self { $this->startsAt = $s; return $this; }
    public function getEndsAt(): ?\DateTimeImmutable { return $this->endsAt; }
    public function setEndsAt(?\DateTimeImmutable $e): self { $this->endsAt = $e; return $this; }
    public function isActive(): bool { return $this->active; }
    public function setActive(bool $a): self { $this->active = $a; return $this; }
    public function getLastGeneratedAt(): ?\DateTimeImmutable { return $this->lastGeneratedAt; }
    public function setLastGeneratedAt(?\DateTimeImmutable $d): self { $this->lastGeneratedAt = $d; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    /**
     * Next occurrence strictly after $after, respecting frequency + day fields.
     * Returns null if there is no occurrence within $endsAt (or if $after is past $endsAt).
     */
    public function nextOccurrenceAfter(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        $after = $after->setTime(0, 0);
        // Never produce dates before startsAt.
        $cursor = $after < $this->startsAt ? $this->startsAt->modify('-1 day') : $after;

        $next = match ($this->frequency) {
            RecurringFrequency::Daily => $cursor->modify('+1 day'),
            RecurringFrequency::Weekly => $this->nextWeekly($cursor),
            RecurringFrequency::Monthly => $this->nextMonthly($cursor),
            RecurringFrequency::Yearly => $this->nextYearly($cursor),
        };

        if ($next < $this->startsAt) {
            $next = $this->startsAt;
        }
        if ($this->endsAt !== null && $next > $this->endsAt) {
            return null;
        }
        return $next;
    }

    private function nextWeekly(\DateTimeImmutable $cursor): \DateTimeImmutable
    {
        $target = $this->dayOfWeek ?? 1; // default Monday
        // PHP's 'N' format: Mon=1..Sun=7, matching our convention.
        $cursorDow = (int) $cursor->format('N');
        $delta = ($target - $cursorDow + 7) % 7;
        if ($delta === 0) {
            $delta = 7;
        }
        return $cursor->modify("+{$delta} day");
    }

    private function nextMonthly(\DateTimeImmutable $cursor): \DateTimeImmutable
    {
        $target = $this->dayOfMonth ?? (int) $cursor->format('j');
        $candidate = $this->clampDay($cursor->modify('first day of this month'), $target);
        if ($candidate <= $cursor) {
            $candidate = $this->clampDay($cursor->modify('first day of next month'), $target);
        }
        return $candidate;
    }

    private function nextYearly(\DateTimeImmutable $cursor): \DateTimeImmutable
    {
        $month = $this->monthOfYear ?? 1;
        $day = $this->dayOfMonth ?? 1;
        $year = (int) $cursor->format('Y');
        $candidate = $this->clampDay($cursor->setDate($year, $month, 1), $day);
        if ($candidate <= $cursor) {
            $candidate = $this->clampDay($cursor->setDate($year + 1, $month, 1), $day);
        }
        return $candidate;
    }

    /** Clamp $day to the last valid day of the month $cursor is in. */
    private function clampDay(\DateTimeImmutable $cursor, int $day): \DateTimeImmutable
    {
        $lastDay = (int) $cursor->format('t');
        return $cursor->setDate(
            (int) $cursor->format('Y'),
            (int) $cursor->format('m'),
            min($day, $lastDay),
        );
    }
}
