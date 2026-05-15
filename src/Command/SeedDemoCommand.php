<?php

namespace App\Command;

use App\Entity\Account;
use App\Entity\AccountType;
use App\Entity\Asset;
use App\Entity\RecurringFrequency;
use App\Entity\RecurringTransaction;
use App\Entity\Transaction;
use App\Entity\TransactionCategory;
use App\Entity\TransactionSource;
use App\Entity\TransactionType;
use App\Entity\User;
use App\Holdings\HoldingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Seeds a self-contained demo user with believable data, for screenshots and
 * onboarding demos. Pass --reset to wipe the demo user (and cascaded data)
 * before reseeding. Deterministic — the same seed run twice yields the same
 * curves and totals.
 */
#[AsCommand(
    name: 'app:seed:demo',
    description: 'Create a demo user with rich sample data (accounts, transactions, holdings, FX, recurring) for screenshots',
)]
class SeedDemoCommand extends Command
{
    private const EMAIL = 'demo@treasury.local';
    private const PASSWORD = 'demo';

    /** ticker => [isin, currency, name, base_price_USD] */
    private const TICKERS = [
        'AAPL' => ['US0378331005', 'USD', 'APPLE INC',              175.0],
        'MSFT' => ['US5949181045', 'USD', 'MICROSOFT CORP',         380.0],
        'NVDA' => ['US67066G1040', 'USD', 'NVIDIA CORP',             80.0],
        'VOO'  => ['US9229083632', 'USD', 'VANGUARD S&P 500 ETF',   440.0],
    ];

    /** Drift per week (~1% = 0.01) and weekly volatility (sigma). */
    private const TICKER_DYNAMICS = [
        'AAPL' => [0.0040, 0.024],
        'MSFT' => [0.0050, 0.022],
        'NVDA' => [0.0120, 0.060],
        'VOO'  => [0.0035, 0.018],
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('reset', null, InputOption::VALUE_NONE, 'Delete the existing demo user (and cascaded data) before reseeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->em->getRepository(User::class)->findOneBy(['email' => self::EMAIL]);
        if ($existing !== null) {
            if (!$input->getOption('reset')) {
                $io->warning(sprintf('Demo user %s already exists. Pass --reset to wipe and reseed.', self::EMAIL));
                return Command::SUCCESS;
            }
            $this->wipeDemo($existing, $io);
        }

        // Deterministic price/spending paths so screenshots reproduce.
        mt_srand(20260101);

        $today = new \DateTimeImmutable('today');
        $start = $today->modify('-24 months')->modify('first day of this month');

        $user = (new User())
            ->setEmail(self::EMAIL)
            ->setBaseCurrency('CHF');
        $user->setPassword($this->hasher->hashPassword($user, self::PASSWORD));
        $this->em->persist($user);

        $accounts = $this->seedAccounts($user);
        $assets = $this->seedAssets();

        $this->seedFxRates($start, $today);
        $this->seedAssetPrices($assets, $start, $today);

        // Flush so the lookup helpers below can read prices + FX from the DB.
        $this->em->flush();

        $this->seedCheckingActivity($accounts['checking'], $accounts['savings'], $accounts['brokerage'], $accounts['pillar3a'], $start, $today);
        $this->seedBrokerageTrades($accounts['brokerage'], $start, $today);
        $this->seedPillar3aActivity($accounts['pillar3a'], $start, $today);
        $this->seedGoldActivity($accounts['gold'], $start, $today);
        $this->seedRecurringRules($accounts['checking'], $accounts['savings'], $accounts['brokerage']);

        $this->em->flush();

        $io->success(sprintf(
            "Demo user ready.\n  email:    %s\n  password: %s\n  accounts: %d\n  range:    %s → %s",
            self::EMAIL,
            self::PASSWORD,
            count($accounts),
            $start->format('Y-m-d'),
            $today->format('Y-m-d'),
        ));

        return Command::SUCCESS;
    }

    /**
     * Cascade-delete the demo user and everything keyed off it. Cleanest path
     * is raw SQL — the orphan-removal cascades on Account → Transaction handle
     * most of it, but we also wipe recurring rules + FX rates that aren't
     * owned by a user.
     */
    private function wipeDemo(User $user, SymfonyStyle $io): void
    {
        $conn = $this->em->getConnection();
        $ownerBin = $user->getId()->toBinary();

        $accountIds = $conn->fetchFirstColumn('SELECT id FROM accounts WHERE owner_id = :o', ['o' => $ownerBin]);
        if ($accountIds !== []) {
            $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
            $conn->executeStatement("DELETE FROM recurring_transactions WHERE account_id IN ($placeholders)", $accountIds);
            $conn->executeStatement("DELETE FROM transactions WHERE account_id IN ($placeholders)", $accountIds);
            $conn->executeStatement("DELETE FROM account_allocations WHERE account_id IN ($placeholders)", $accountIds);
            $conn->executeStatement("DELETE FROM accounts WHERE id IN ($placeholders)", $accountIds);
        }
        $conn->executeStatement('DELETE FROM password_reset_tokens WHERE user_id = :o', ['o' => $ownerBin]);
        $conn->executeStatement('DELETE FROM users WHERE id = :o', ['o' => $ownerBin]);

        $this->em->clear();
        $io->note('Wiped previous demo user and cascaded data.');
    }

    /** @return array<string, Account> */
    private function seedAccounts(User $user): array
    {
        $defs = [
            'checking' => ['Everyday Checking',    'ZKB',             AccountType::BankChecking,   'CHF'],
            'savings'  => ['High-Yield Savings',   'ZKB',             AccountType::BankSavings,    'CHF'],
            'brokerage'=> ['Investments',          'Interactive Brokers', AccountType::Brokerage,  'USD'],
            'pillar3a' => ['3a · VIAC',            'VIAC',            AccountType::Pillar3a,       'CHF'],
            'gold'     => ['Gold Reserve',         null,              AccountType::PreciousMetals, 'CHF'],
        ];

        $out = [];
        foreach ($defs as $key => [$name, $institution, $type, $currency]) {
            $a = (new Account())
                ->setOwner($user)
                ->setName($name)
                ->setInstitution($institution)
                ->setType($type)
                ->setCurrency($currency);
            $this->em->persist($a);
            $out[$key] = $a;
        }
        return $out;
    }

    /** @return array<string, Asset> */
    private function seedAssets(): array
    {
        $repo = $this->em->getRepository(Asset::class);
        $out = [];
        foreach (self::TICKERS as $ticker => [$isin, $currency, $name]) {
            $a = $repo->findOneBy(['isin' => $isin]) ?? (new Asset())
                ->setIsin($isin)
                ->setTicker($ticker)
                ->setName($name)
                ->setCurrency($currency);
            if ($a->getId() === null || !$this->em->contains($a)) {
                $this->em->persist($a);
            }
            $out[$ticker] = $a;
        }

        // Spot gold reference (priced in USD per troy ounce). Only created if
        // app:seed:coins hasn't run yet.
        $spot = $repo->findOneBy(['isin' => HoldingsService::SPOT_GOLD_ISIN]);
        if ($spot === null) {
            $spot = (new Asset())
                ->setIsin(HoldingsService::SPOT_GOLD_ISIN)
                ->setTicker('GC=F')
                ->setName('Gold spot (COMEX GC=F)')
                ->setCurrency('USD');
            $this->em->persist($spot);
        }
        $out['GOLD'] = $spot;

        // A 1-oz Maple Leaf coin we'll buy on the gold account. Created here
        // if seed:coins hasn't populated yet.
        $maple = $repo->findOneBy(['isin' => 'COIN:MAPLE-1OZ']);
        if ($maple === null) {
            $maple = (new Asset())
                ->setIsin('COIN:MAPLE-1OZ')
                ->setName('Canadian Maple Leaf 1 oz')
                ->setCurrency('USD')
                ->setUnitWeightGrams('31.1035')
                ->setPricePremiumPct('3.0');
            $this->em->persist($maple);
        }
        $out['MAPLE'] = $maple;

        return $out;
    }

    /**
     * Weekly FX rates: CHF/USD and CHF/EUR with small random walks around plausible
     * mid-rates. Bulk INSERT IGNORE so we don't clobber any real rates the user has
     * already pulled — existing dates win, we fill the gaps.
     */
    private function seedFxRates(\DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $pairs = [
            ['CHF', 'USD', 1.10, 0.008],
            ['USD', 'CHF', 0.91, 0.008],
            ['CHF', 'EUR', 1.02, 0.006],
            ['EUR', 'CHF', 0.98, 0.006],
        ];
        $values = [];
        $params = [];
        foreach ($pairs as [$from, $to, $mid, $sigma]) {
            $rate = $mid;
            $cursor = $start;
            while ($cursor <= $end) {
                $rate += $this->normalish() * $sigma;
                $rate = max(0.5, min(2.0, $rate));
                $values[] = '(?, ?, ?, ?, ?)';
                $params[] = Uuid::v7()->toBinary();
                $params[] = $cursor->format('Y-m-d');
                $params[] = $from;
                $params[] = $to;
                $params[] = number_format($rate, 8, '.', '');
                $cursor = $cursor->modify('+7 days');
            }
        }
        $this->em->getConnection()->executeStatement(
            'INSERT IGNORE INTO fx_rates (id, occurred_at, from_currency, to_currency, rate) VALUES ' . implode(',', $values),
            $params,
        );
    }

    /**
     * Weekly asset prices via INSERT IGNORE. Random walk per ticker (deterministic
     * via the seeded mt_srand) so performance curves look real but don't change
     * between runs. Existing prices win — we don't overwrite real history.
     *
     * @param array<string, Asset> $assets
     */
    private function seedAssetPrices(array $assets, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $values = [];
        $params = [];

        foreach (self::TICKERS as $ticker => [$_, $currency, $__, $basePrice]) {
            [$drift, $sigma] = self::TICKER_DYNAMICS[$ticker];
            $price = $basePrice;
            $cursor = $start;
            $assetIdBin = $assets[$ticker]->getId()->toBinary();
            while ($cursor <= $end) {
                $r = $drift + $sigma * $this->normalish();
                $price = max(1.0, $price * (1.0 + $r));
                $values[] = '(?, ?, ?, ?, ?)';
                $params[] = Uuid::v7()->toBinary();
                $params[] = $assetIdBin;
                $params[] = $cursor->format('Y-m-d');
                $params[] = (int) round($price * 100);
                $params[] = $currency;
                $cursor = $cursor->modify('+7 days');
            }
        }

        // Gold spot: USD per troy ounce, starting around 2100.
        $price = 2100.0;
        $cursor = $start;
        $goldIdBin = $assets['GOLD']->getId()->toBinary();
        while ($cursor <= $end) {
            $r = 0.0045 + 0.020 * $this->normalish();
            $price = max(500.0, $price * (1.0 + $r));
            $values[] = '(?, ?, ?, ?, ?)';
            $params[] = Uuid::v7()->toBinary();
            $params[] = $goldIdBin;
            $params[] = $cursor->format('Y-m-d');
            $params[] = (int) round($price * 100);
            $params[] = 'USD';
            $cursor = $cursor->modify('+7 days');
        }

        $this->em->getConnection()->executeStatement(
            'INSERT IGNORE INTO prices (id, asset_id, occurred_at, price_minor, currency) VALUES ' . implode(',', $values),
            $params,
        );
    }

    /**
     * Two years of checking activity: salary in, rent + utilities + insurance +
     * groceries + dining + transport + subscriptions out. Tags applied where it
     * makes sense ("netflix", "spotify", "migros", "coop", "sbb").
     */
    private function seedCheckingActivity(
        Account $checking,
        Account $savings,
        Account $brokerage,
        Account $pillar3a,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): void {
        $cursor = $start;
        while ($cursor <= $end) {
            $monthStart = $cursor->modify('first day of this month');

            // === Income
            $salaryDay = $this->dateInMonth($monthStart, 25);
            if ($salaryDay <= $end) {
                $this->tx($checking, $salaryDay, 720000, TransactionType::Deposit, 'Salary — Acme AG', TransactionCategory::Salary);
            }

            // === Fixed monthly outflows
            $rentDay = $this->dateInMonth($monthStart, 1);
            if ($rentDay <= $end) {
                $this->tx($checking, $rentDay, -245000, TransactionType::Withdrawal, 'Rent — apartment Zürich', TransactionCategory::Rent);
            }
            $utilDay = $this->dateInMonth($monthStart, 5);
            if ($utilDay <= $end) {
                $this->tx($checking, $utilDay, -9800, TransactionType::Withdrawal, 'EWZ electricity', TransactionCategory::Utilities);
                $this->tx($checking, $utilDay, -6500, TransactionType::Withdrawal, 'Swisscom internet + mobile', TransactionCategory::Utilities);
            }
            $insDay = $this->dateInMonth($monthStart, 8);
            if ($insDay <= $end) {
                $this->tx($checking, $insDay, -38000, TransactionType::Withdrawal, 'Health insurance — Helsana', TransactionCategory::Insurance);
            }

            // === Subscriptions (with tags)
            $subDay = $this->dateInMonth($monthStart, 3);
            if ($subDay <= $end) {
                $this->tx($checking, $subDay, -2390, TransactionType::Withdrawal, 'Netflix Premium', TransactionCategory::Subscriptions, ['netflix', 'streaming']);
                $this->tx($checking, $subDay, -1690, TransactionType::Withdrawal, 'Spotify Family', TransactionCategory::Subscriptions, ['spotify', 'streaming']);
                $this->tx($checking, $subDay->modify('+1 day'), -990, TransactionType::Withdrawal, 'GitHub Pro', TransactionCategory::Subscriptions, ['github', 'work']);
                $this->tx($checking, $subDay->modify('+2 day'), -1290, TransactionType::Withdrawal, 'iCloud+ 200GB', TransactionCategory::Subscriptions, ['apple']);
            }

            // === Transfers out: monthly to savings and brokerage, and 3a Jan only.
            $xferDay = $this->dateInMonth($monthStart, 26);
            if ($xferDay <= $end) {
                $this->tx($checking, $xferDay, -80000, TransactionType::Withdrawal, 'Transfer to savings', TransactionCategory::Savings);
                $this->tx($savings, $xferDay, 80000, TransactionType::Deposit, 'Transfer from checking', TransactionCategory::Transfer);

                $this->tx($checking, $xferDay, -120000, TransactionType::Withdrawal, 'Transfer to brokerage', TransactionCategory::Transfer);
                $chfToUsd = $this->lookupFx('CHF', 'USD', $xferDay) ?? 1.10;
                $usdMinor = (int) round(120000 * $chfToUsd);
                $this->tx($brokerage, $xferDay, $usdMinor, TransactionType::Deposit, 'Transfer from checking (FX CHF→USD)', TransactionCategory::Transfer);
            }
            if ((int) $monthStart->format('n') === 1) {
                $contribDay = $this->dateInMonth($monthStart, 15);
                if ($contribDay <= $end) {
                    $this->tx($checking, $contribDay, -708300, TransactionType::Withdrawal, '3a max contribution', TransactionCategory::Savings);
                    $this->tx($pillar3a, $contribDay, 708300, TransactionType::Deposit, 'Yearly 3a contribution', TransactionCategory::Transfer);
                }
            }

            // === Day-to-day spending
            $this->seedMonthSpending($checking, $monthStart, $end);

            $cursor = $monthStart->modify('first day of next month');
        }
    }

    /** Four weeks of grocery + dining + transport + occasional shopping. */
    private function seedMonthSpending(Account $checking, \DateTimeImmutable $monthStart, \DateTimeImmutable $end): void
    {
        for ($week = 0; $week < 4; $week++) {
            $weekStart = $monthStart->modify("+{$week} weeks");
            // Groceries — Migros + Coop alternating
            $g1 = $weekStart->modify('+1 day');
            if ($g1 <= $end) {
                $isMigros = ($week + (int) $monthStart->format('n')) % 2 === 0;
                $shop = $isMigros ? 'Migros' : 'Coop';
                $amt = -(($week * 17 + (int) $monthStart->format('n')) % 80 + 70) * 100;
                $this->tx($checking, $g1, $amt, TransactionType::Withdrawal, "$shop Zürich HB", TransactionCategory::Groceries, [strtolower($shop), 'groceries']);
            }
            // Dining
            $d = $weekStart->modify('+4 days');
            if ($d <= $end) {
                $amt = -(($week * 11 + (int) $monthStart->format('n')) % 50 + 25) * 100;
                $names = ['Hiltl', 'Sternen Grill', 'Frau Gerolds', 'Restaurant Kornhauskeller'];
                $this->tx($checking, $d, $amt, TransactionType::Withdrawal, $names[$week % 4], TransactionCategory::Dining);
            }
            // Transport — SBB
            $t = $weekStart->modify('+2 days');
            if ($t <= $end) {
                $amt = -(($week * 7 + (int) $monthStart->format('n')) % 40 + 15) * 100;
                $this->tx($checking, $t, $amt, TransactionType::Withdrawal, 'SBB ticket', TransactionCategory::Transport, ['sbb']);
            }
        }

        // One bigger shopping trip per month
        $shopDay = $this->dateInMonth($monthStart, 18);
        if ($shopDay <= $end) {
            $amt = -((int) $monthStart->format('n') * 23 % 350 + 80) * 100;
            $places = ['Amazon', 'Zalando', 'Galaxus', 'Migros Online'];
            $tag = strtolower($places[(int) $monthStart->format('n') % 4]);
            $this->tx($checking, $shopDay, $amt, TransactionType::Withdrawal, $places[(int) $monthStart->format('n') % 4], TransactionCategory::Shopping, [$tag]);
        }
    }

    /**
     * Brokerage trades over the period. Amount is in USD (brokerage's base currency)
     * and the asset's price-at-trade is fetched from the seeded price history so
     * the cost basis matches reality.
     */
    private function seedBrokerageTrades(Account $brokerage, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        // Spread 12 trades across the 24-month range.
        $months = [2, 5, 8, 11, 14, 17, 20, 23];
        $picks = ['VOO', 'AAPL', 'NVDA', 'MSFT', 'VOO', 'NVDA', 'AAPL', 'VOO'];
        $qtys =  [10,    8,      6,      4,      6,    5,      4,      5];

        foreach ($months as $i => $monthsFromStart) {
            $day = $start->modify("+{$monthsFromStart} months")->modify('+10 days');
            if ($day > $end) continue;

            $ticker = $picks[$i];
            $qty = $qtys[$i];
            [$isin] = self::TICKERS[$ticker];
            $price = $this->lookupPriceUsd($ticker, $day);
            $usdAmount = (int) round(-$qty * $price * 100);

            $this->tx(
                $brokerage,
                $day,
                $usdAmount,
                TransactionType::TradeBuy,
                sprintf('Buy %d %s @ $%.2f', $qty, $ticker, $price),
                null,
                [],
                $isin,
                (string) $qty,
            );
        }

        // One partial sell to give the performance chart something interesting.
        $sellDay = $start->modify('+15 months')->modify('+5 days');
        if ($sellDay <= $end) {
            $sellQty = 4;
            $price = $this->lookupPriceUsd('NVDA', $sellDay);
            $usdAmount = (int) round($sellQty * $price * 100);
            $this->tx(
                $brokerage,
                $sellDay,
                $usdAmount,
                TransactionType::TradeSell,
                sprintf('Sell %d NVDA @ $%.2f', $sellQty, $price),
                null,
                [],
                self::TICKERS['NVDA'][0],
                (string) -$sellQty,
            );
        }

        // A couple of dividends to populate the dividend tracker.
        $divs = [
            ['+8 months',  'AAPL', 25],
            ['+14 months', 'VOO',  60],
            ['+20 months', 'MSFT', 22],
        ];
        foreach ($divs as [$offset, $ticker, $amountUsd]) {
            $day = $start->modify($offset);
            if ($day > $end) continue;
            $this->tx(
                $brokerage,
                $day,
                $amountUsd * 100,
                TransactionType::Dividend,
                "Dividend — $ticker",
                TransactionCategory::Dividend,
                [],
                self::TICKERS[$ticker][0],
            );
        }
    }

    /**
     * 3a: yearly contributions (already seeded from checking transfer) get
     * invested in VOO. Each January we buy ETF units with the cash that just landed.
     */
    private function seedPillar3aActivity(Account $pillar3a, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $cursor = $start;
        while ($cursor <= $end) {
            if ((int) $cursor->format('n') === 1) {
                $tradeDay = $this->dateInMonth($cursor, 20);
                if ($tradeDay <= $end) {
                    // Buy ~15 units of VOO with last month's contribution
                    $priceUsd = $this->lookupPriceUsd('VOO', $tradeDay);
                    $qty = 15;
                    $chfRate = $this->lookupFx('USD', 'CHF', $tradeDay) ?? 0.91;
                    $chfMinor = -(int) round($qty * $priceUsd * $chfRate * 100);
                    $this->tx(
                        $pillar3a,
                        $tradeDay,
                        $chfMinor,
                        TransactionType::TradeBuy,
                        sprintf('Buy %d VOO @ $%.2f (3a)', $qty, $priceUsd),
                        null,
                        ['3a'],
                        self::TICKERS['VOO'][0],
                        (string) $qty,
                    );
                }
            }
            $cursor = $cursor->modify('first day of next month');
        }
    }

    /** A handful of gold coin purchases over the period. */
    private function seedGoldActivity(Account $gold, \DateTimeImmutable $start, \DateTimeImmutable $end): void
    {
        $buys = [
            ['+3 months',  2],
            ['+9 months',  3],
            ['+15 months', 2],
            ['+21 months', 3],
        ];
        foreach ($buys as [$offset, $coins]) {
            $day = $start->modify($offset);
            if ($day > $end) continue;
            $spotUsd = $this->lookupPriceUsd('GOLD', $day);
            $chfRate = $this->lookupFx('USD', 'CHF', $day) ?? 0.91;
            $coinUsd = $spotUsd * 1.03; // premium baked in here for the transaction; asset row has its own
            $chfMinor = -(int) round($coins * $coinUsd * $chfRate * 100);
            $this->tx(
                $gold,
                $day,
                $chfMinor,
                TransactionType::TradeBuy,
                sprintf('Buy %d × Maple Leaf 1 oz', $coins),
                null,
                ['gold', 'physical'],
                'COIN:MAPLE-1OZ',
                (string) $coins,
            );
        }
    }

    private function seedRecurringRules(Account $checking, Account $savings, Account $brokerage): void
    {
        $rules = [
            ['Salary — Acme AG',         720000, 'CHF', TransactionType::Deposit,    TransactionCategory::Salary,        RecurringFrequency::Monthly, 25, null, $checking],
            ['Rent — apartment Zürich', -245000, 'CHF', TransactionType::Withdrawal, TransactionCategory::Rent,          RecurringFrequency::Monthly,  1, null, $checking],
            ['Netflix Premium',           -2390, 'CHF', TransactionType::Withdrawal, TransactionCategory::Subscriptions, RecurringFrequency::Monthly,  3, null, $checking],
            ['Spotify Family',            -1690, 'CHF', TransactionType::Withdrawal, TransactionCategory::Subscriptions, RecurringFrequency::Monthly,  3, null, $checking],
            ['Transfer to savings',      -80000, 'CHF', TransactionType::Withdrawal, TransactionCategory::Savings,       RecurringFrequency::Monthly, 26, null, $checking],
            ['Transfer to brokerage',   -120000, 'CHF', TransactionType::Withdrawal, TransactionCategory::Transfer,      RecurringFrequency::Monthly, 26, null, $checking],
        ];

        $today = new \DateTimeImmutable('today');
        foreach ($rules as [$desc, $amount, $ccy, $type, $cat, $freq, $dom, $dow, $account]) {
            $r = (new RecurringTransaction())
                ->setAccount($account)
                ->setDescription($desc)
                ->setAmountMinor($amount)
                ->setCurrency($ccy)
                ->setType($type)
                ->setCategory($cat)
                ->setFrequency($freq)
                ->setDayOfMonth($dom)
                ->setDayOfWeek($dow)
                ->setStartsAt($today->modify('-24 months')->modify('first day of this month'))
                ->setLastGeneratedAt($today)
                ->setActive(true);
            $this->em->persist($r);
        }
    }

    // ────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────

    /** Persist a transaction with category + tags. */
    private function tx(
        Account $account,
        \DateTimeImmutable $when,
        int $amountMinor,
        TransactionType $type,
        string $description,
        ?TransactionCategory $category = null,
        array $tags = [],
        ?string $assetIsin = null,
        ?string $assetQuantity = null,
    ): void {
        $tx = new Transaction();
        $tx->setAccount($account);
        $tx->setOccurredAt($when);
        $tx->setAmountMinor($amountMinor);
        $tx->setCurrency($account->getCurrency());
        $tx->setDescription($description);
        $tx->setType($type);
        $tx->setSource(TransactionSource::Manual);
        if ($category !== null) {
            $tx->setCategory($category);
        }
        if ($tags !== []) {
            $tx->setTags($tags);
        }
        if ($assetIsin !== null) {
            $tx->setAssetIsin($assetIsin);
        }
        if ($assetQuantity !== null) {
            $tx->setAssetQuantity($assetQuantity);
        }
        $this->em->persist($tx);
    }

    /** Day-of-month, clamped to last valid day. */
    private function dateInMonth(\DateTimeImmutable $monthStart, int $day): \DateTimeImmutable
    {
        $lastDay = (int) $monthStart->format('t');
        return $monthStart->setDate(
            (int) $monthStart->format('Y'),
            (int) $monthStart->format('m'),
            min($day, $lastDay),
        );
    }

    /** Approximate standard normal via Box-Muller (uses mt_rand, deterministic). */
    private function normalish(): float
    {
        $u1 = max(1e-9, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();
        return sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    /** Read back the seeded weekly price for a ticker at $when. Walks the in-memory unit of work. */
    private function lookupPriceUsd(string $tickerOrSpecial, \DateTimeImmutable $when): float
    {
        $isin = $tickerOrSpecial === 'GOLD'
            ? HoldingsService::SPOT_GOLD_ISIN
            : self::TICKERS[$tickerOrSpecial][0];

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT p.price_minor, p.occurred_at FROM prices p
             INNER JOIN assets a ON a.id = p.asset_id
             WHERE a.isin = :isin AND p.occurred_at <= :d
             ORDER BY p.occurred_at DESC LIMIT 1',
            ['isin' => $isin, 'd' => $when->format('Y-m-d')],
        );
        if ($rows !== []) {
            return ((int) $rows[0]['price_minor']) / 100;
        }
        // Fall back to base price (the seeded prices haven't flushed yet on the first lookup).
        return self::TICKERS[$tickerOrSpecial][3] ?? 2100.0;
    }

    /** Latest FX rate on-or-before $when. */
    private function lookupFx(string $from, string $to, \DateTimeImmutable $when): ?float
    {
        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT rate FROM fx_rates
             WHERE from_currency = :f AND to_currency = :t AND occurred_at <= :d
             ORDER BY occurred_at DESC LIMIT 1',
            ['f' => $from, 't' => $to, 'd' => $when->format('Y-m-d')],
        );
        return $rows !== [] ? (float) $rows[0]['rate'] : null;
    }
}
