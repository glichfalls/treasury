<?php

namespace App\Plan;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\User;
use App\Fx\FxConverter;
use App\Holdings\HoldingsService;
use App\Repository\AccountRepository;
use App\TimeSeries\PerformanceService;
use Doctrine\DBAL\Connection;

/**
 * Derives per-account inputs for the retirement projection in PlanView: starting
 * value (in the user's base currency), historical annualized contribution rate,
 * historical CAGR, and historical volatility — each computed over a user-chosen
 * window (1y / 3y / 5y / since inception).
 *
 * The frontend uses these as defaults; users can override any field per account.
 *
 * Volatility is annualized monthly-return stddev (std dev of monthly TWR
 * increments × √12), which is the conventional finance definition and makes
 * "expected ± vol" map directly to the ±1σ band the chart draws.
 */
final class PlanService
{
    /** Account types that contribute to long-term wealth building. */
    private const INVESTABLE_TYPES = [
        AccountType::Brokerage,
        AccountType::CryptoExchange,
        AccountType::CryptoWallet,
        AccountType::PreciousMetals,
        AccountType::Pillar3a,
        AccountType::RealEstate,
    ];

    /** Minimum data span (years) before we trust historical defaults. */
    private const MIN_YEARS_FOR_HISTORY = 0.5;

    public function __construct(
        private readonly AccountRepository $accounts,
        private readonly HoldingsService $holdings,
        private readonly PerformanceService $performance,
        private readonly FxConverter $fx,
        private readonly Connection $conn,
    ) {}

    /**
     * @return list<array{
     *   id: string, name: string, type: string, currency: string,
     *   startingMinor: string, startingMinorBase: string, baseCurrency: string,
     *   historicalContribAnnualMinorBase: ?string,
     *   historicalReturnPct: ?float,
     *   historicalVolPct: ?float,
     *   windowYearsAvailable: float,
     *   hasSufficientHistory: bool,
     * }>
     */
    public function inputsForUser(User $user, PlanWindow $window): array
    {
        $base = $user->getBaseCurrency();
        $today = new \DateTimeImmutable('today');

        $out = [];
        foreach ($this->accounts->findByOwner($user) as $account) {
            if (!in_array($account->getType(), self::INVESTABLE_TYPES, true)) {
                continue;
            }
            $out[] = $this->inputsForAccount($account, $base, $window, $today);
        }
        return $out;
    }

    /**
     * @return array{
     *   id: string, name: string, type: string, currency: string,
     *   startingMinor: string, startingMinorBase: string, baseCurrency: string,
     *   historicalContribAnnualMinorBase: ?string,
     *   historicalReturnPct: ?float,
     *   historicalVolPct: ?float,
     *   windowYearsAvailable: float,
     *   hasSufficientHistory: bool,
     * }
     */
    private function inputsForAccount(
        Account $account,
        string $baseCurrency,
        PlanWindow $window,
        \DateTimeImmutable $today,
    ): array {
        $native = $account->getCurrency();

        // Current value in account-native currency: cash + holdings.
        $cash = $this->accounts->sumBalancesMinor([$account->getId()])[$account->getId()->toRfc4122()] ?? '0';
        $holdings = $this->holdings->forAccount($account);
        $holdingsValue = $this->holdings->totalValueMinor($account, $holdings);
        $startingNative = bcadd($cash, $holdingsValue, 0);

        $startingBase = $this->fx->convertMinor((int) $startingNative, $native, $baseCurrency, $today)
            ?? (int) $startingNative;

        // Window resolution: clamp to first transaction so a 5y window on a 1y
        // account doesn't claim to have 5y of data.
        $firstTxDate = $this->firstTransactionDate($account);
        $windowFrom = $window->startDate($today);
        if ($firstTxDate !== null && $firstTxDate > $windowFrom) {
            $windowFrom = $firstTxDate;
        }
        $yearsAvailable = $firstTxDate === null ? 0.0 : $this->yearsBetween($windowFrom, $today);
        $hasHistory = $yearsAvailable >= self::MIN_YEARS_FOR_HISTORY;

        $contribAnnual = null;
        $returnPct = null;
        $volPct = null;

        if ($hasHistory) {
            $contribAnnual = $this->annualizedContributionMinor(
                $account,
                $windowFrom,
                $today,
                $baseCurrency,
                $yearsAvailable,
            );

            $monthly = $this->performance->forAccount($account, $windowFrom, $today, 'monthly');
            [$returnPct, $volPct] = $this->returnAndVolFromMonthlyTwr($monthly, $yearsAvailable);
        }

        return [
            'id' => $account->getId()->toRfc4122(),
            'name' => $account->getName(),
            'type' => $account->getType()->value,
            'currency' => $native,
            'startingMinor' => $startingNative,
            'startingMinorBase' => (string) $startingBase,
            'baseCurrency' => $baseCurrency,
            'historicalContribAnnualMinorBase' => $contribAnnual === null ? null : (string) $contribAnnual,
            'historicalReturnPct' => $returnPct === null ? null : round($returnPct, 2),
            'historicalVolPct' => $volPct === null ? null : round($volPct, 2),
            'windowYearsAvailable' => round($yearsAvailable, 2),
            'hasSufficientHistory' => $hasHistory,
        ];
    }

    private function firstTransactionDate(Account $account): ?\DateTimeImmutable
    {
        $row = $this->conn->fetchAssociative(
            'SELECT MIN(occurred_at) AS first_date FROM transactions WHERE account_id = :id',
            ['id' => $account->getId()->toBinary()],
        );
        if ($row === false || $row['first_date'] === null) {
            return null;
        }
        return new \DateTimeImmutable($row['first_date']);
    }

    /**
     * Sum of deposit − withdrawal over the window (in base currency, converted at
     * each transaction's date), divided by the number of years available. Returns
     * an integer minor-units value or null if no flow data.
     */
    private function annualizedContributionMinor(
        Account $account,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        string $baseCurrency,
        float $yearsAvailable,
    ): ?int {
        if ($yearsAvailable <= 0) {
            return null;
        }
        $rows = $this->conn->fetchAllAssociative(
            "SELECT occurred_at, amount_minor, currency
             FROM transactions
             WHERE account_id = :id
               AND occurred_at BETWEEN :from AND :to
               AND type IN ('deposit', 'withdrawal')",
            [
                'id' => $account->getId()->toBinary(),
                'from' => $from->format('Y-m-d'),
                'to' => $to->format('Y-m-d'),
            ],
        );
        $total = 0;
        foreach ($rows as $r) {
            $amount = (int) $r['amount_minor'];
            $ccy = $r['currency'];
            if (strtoupper($ccy) === strtoupper($baseCurrency)) {
                $total += $amount;
            } else {
                $converted = $this->fx->convertMinor($amount, $ccy, $baseCurrency, new \DateTimeImmutable($r['occurred_at']));
                $total += $converted ?? $amount;
            }
        }
        return (int) round($total / $yearsAvailable);
    }

    /**
     * Given monthly cumulative-TWR points from PerformanceService, derive:
     *  - annualized return (CAGR): ((1 + lastTwr/100) ^ (1/years)) - 1
     *  - annualized vol: stddev(monthly increments) × √12
     *
     * Monthly increment between two cumulative-TWR points $a (prev) and $b (curr):
     *  r = (1 + b/100) / (1 + a/100) − 1
     *
     * The cumulative-TWR series is noisy at the boundaries:
     *  - First period contains account-initialization artifacts (prev_total can
     *    be tiny relative to next month's real activity → huge spurious return).
     *  - Holdings briefly value at 0 when an asset is missing a price for some
     *    historical date, then snap back when a price is found, producing fake
     *    -100% / +100% monthly swings.
     * Both inflate vol massively. Mitigation: skip the first monthly increment
     * and clip remaining monthly returns to ±MAX_MONTHLY_RETURN. The clip is
     * loose enough that real high-vol assets (crypto can do ±30% in a month)
     * are preserved while obvious data artifacts get tamed.
     *
     * @param \App\TimeSeries\PerformancePoint[] $points
     * @return array{0: ?float, 1: ?float}  [returnPct, volPct]
     */
    private const MAX_MONTHLY_RETURN = 0.50;

    private function returnAndVolFromMonthlyTwr(array $points, float $years): array
    {
        if (count($points) < 2 || $years <= 0) {
            return [null, null];
        }
        $last = end($points);
        $cumFraction = $last->twrPct / 100.0;
        $cagr = $cumFraction <= -1.0
            ? null
            : ((1 + $cumFraction) ** (1 / $years)) - 1;

        // Drop the first increment (initialization noise) and clip the rest.
        $monthly = [];
        for ($i = 2, $n = count($points); $i < $n; $i++) {
            $prev = 1 + $points[$i - 1]->twrPct / 100.0;
            $curr = 1 + $points[$i]->twrPct / 100.0;
            if ($prev <= 0) {
                continue;
            }
            $r = ($curr / $prev) - 1;
            if ($r > self::MAX_MONTHLY_RETURN) {
                $r = self::MAX_MONTHLY_RETURN;
            } elseif ($r < -self::MAX_MONTHLY_RETURN) {
                $r = -self::MAX_MONTHLY_RETURN;
            }
            $monthly[] = $r;
        }
        if (count($monthly) < 2) {
            return [$cagr === null ? null : $cagr * 100, null];
        }

        $mean = array_sum($monthly) / count($monthly);
        $sqSum = 0.0;
        foreach ($monthly as $r) {
            $sqSum += ($r - $mean) ** 2;
        }
        $stddev = sqrt($sqSum / (count($monthly) - 1));
        $annualVol = $stddev * sqrt(12);

        return [
            $cagr === null ? null : $cagr * 100,
            $annualVol * 100,
        ];
    }

    private function yearsBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        return max(0.0, $seconds / (365.25 * 86400));
    }
}
