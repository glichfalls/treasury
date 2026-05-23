<?php

namespace App\Dashboard;

use App\Entity\User;
use App\Fx\FxConverter;
use App\Holdings\HoldingsService;
use App\Repository\AssetRepository;
use App\Repository\PriceRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Cross-account aggregations for the dashboard: today's P&L, top movers, and
 * recent activity. Everything is expressed in the user's base currency.
 *
 * Day-change % is asset-level (same across accounts). Day P&L is the sum across
 * every account that holds the asset, FX-converted at today's rate.
 */
final class DashboardService
{
    private const TROY_OUNCE_GRAMS = 31.1034768;

    public function __construct(
        private readonly Connection $conn,
        private readonly AssetRepository $assets,
        private readonly PriceRepository $prices,
        private readonly FxConverter $fx,
    ) {}

    /**
     * Per-asset day-over-day P&L for everything the user holds.
     *
     * @return list<array{
     *   isin: string,
     *   ticker: ?string,
     *   name: ?string,
     *   quantity: string,
     *   priceCurrency: ?string,
     *   latestPriceMinor: ?string,
     *   previousPriceMinor: ?string,
     *   dayChangePct: ?float,
     *   dayPnlBaseMinor: ?string,
     *   valueBaseMinor: ?string,
     *   previousValueBaseMinor: ?string
     * }>
     */
    public function assetDayChanges(User $user): array
    {
        $base = $user->getBaseCurrency();
        $today = new \DateTimeImmutable('today');

        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.asset_isin, SUM(t.asset_quantity) AS qty
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner AND t.asset_isin IS NOT NULL AND t.asset_quantity IS NOT NULL
             GROUP BY t.asset_isin
             HAVING SUM(t.asset_quantity) <> 0',
            ['owner' => $user->getId()->toBinary()],
        );
        if ($rows === []) {
            return [];
        }

        $isins = array_column($rows, 'asset_isin');
        $assetsByIsin = [];
        foreach ($this->assets->findBy(['isin' => $isins]) as $asset) {
            $assetsByIsin[$asset->getIsin()] = $asset;
        }

        $assetIds = array_map(fn($a) => $a->getId(), $assetsByIsin);

        // Spot is needed for commodity-backed coins. Pulled in alongside the
        // rest so we don't issue extra queries per holding.
        $spotAsset = $this->assets->findByIsin(HoldingsService::SPOT_GOLD_ISIN);
        if ($spotAsset !== null) {
            $assetIds[] = $spotAsset->getId();
        }

        $lastTwo = $this->prices->findLatestTwoByAssetIds($assetIds);

        $spotLatest = $spotAsset !== null ? ($lastTwo[$spotAsset->getId()->toRfc4122()][0] ?? null) : null;
        $spotPrev = $spotAsset !== null ? ($lastTwo[$spotAsset->getId()->toRfc4122()][1] ?? null) : null;

        $out = [];
        foreach ($rows as $r) {
            $isin = $r['asset_isin'];
            $asset = $assetsByIsin[$isin] ?? null;
            $qty = (string) $r['qty'];

            if ($asset === null) {
                continue;
            }

            // Commodity coins: derive per-unit price from gold spot.
            if ($asset->getUnitWeightGrams() !== null) {
                if ($spotLatest === null || $spotPrev === null) {
                    continue;
                }
                $grams = (float) $asset->getUnitWeightGrams();
                $premium = (float) ($asset->getPricePremiumPct() ?? '0') / 100;
                $perUnit = function (float $spotPerOzMinor) use ($grams, $premium): float {
                    $perGram = $spotPerOzMinor / 100 / self::TROY_OUNCE_GRAMS;
                    return $perGram * $grams * (1 + $premium);
                };
                $latestMajor = $perUnit((float) $spotLatest['priceMinor']);
                $prevMajor = $perUnit((float) $spotPrev['priceMinor']);
                $priceCcy = $spotLatest['currency'];
            } else {
                $two = $lastTwo[$asset->getId()->toRfc4122()] ?? [];
                if (count($two) < 2) {
                    continue;
                }
                $latestMajor = (float) $two[0]['priceMinor'] / 100;
                $prevMajor = (float) $two[1]['priceMinor'] / 100;
                $priceCcy = $two[0]['currency'];
            }

            if ($prevMajor === 0.0) {
                continue;
            }

            $dayChangePct = ($latestMajor - $prevMajor) / $prevMajor * 100;
            $valueNative = (float) $qty * $latestMajor;
            $prevValueNative = (float) $qty * $prevMajor;
            $pnlNative = $valueNative - $prevValueNative;

            $fxRate = 1.0;
            if ($priceCcy !== $base) {
                $rate = $this->fx->rate($priceCcy, $base, $today);
                if ($rate === null) {
                    continue;
                }
                $fxRate = $rate;
            }

            $out[] = [
                'isin' => $isin,
                'ticker' => $asset->getTicker(),
                'name' => $asset->getName(),
                'quantity' => $qty,
                'priceCurrency' => $priceCcy,
                'latestPriceMinor' => (string) (int) round($latestMajor * 100),
                'previousPriceMinor' => (string) (int) round($prevMajor * 100),
                'dayChangePct' => $dayChangePct,
                'dayPnlBaseMinor' => (string) (int) round($pnlNative * $fxRate * 100),
                'valueBaseMinor' => (string) (int) round($valueNative * $fxRate * 100),
                'previousValueBaseMinor' => (string) (int) round($prevValueNative * $fxRate * 100),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *   gainers: list<array<string, mixed>>,
     *   losers: list<array<string, mixed>>,
     *   baseCurrency: string
     * }
     */
    public function topMovers(User $user, int $limit = 5): array
    {
        $rows = $this->assetDayChanges($user);

        // Drop zero-movers from both lists so we don't dilute the view with
        // assets that just happen to have ticked nothing today.
        $nonZero = array_filter($rows, fn($r) => abs((float) $r['dayChangePct']) > 0.0001);

        $gainers = array_values(array_filter($nonZero, fn($r) => (float) $r['dayChangePct'] > 0));
        $losers = array_values(array_filter($nonZero, fn($r) => (float) $r['dayChangePct'] < 0));

        usort($gainers, fn($a, $b) => $b['dayChangePct'] <=> $a['dayChangePct']);
        usort($losers, fn($a, $b) => $a['dayChangePct'] <=> $b['dayChangePct']);

        return [
            'baseCurrency' => $user->getBaseCurrency(),
            'gainers' => array_slice($gainers, 0, $limit),
            'losers' => array_slice($losers, 0, $limit),
        ];
    }

    /**
     * @return array{
     *   baseCurrency: string,
     *   pnlBaseMinor: string,
     *   previousValueBaseMinor: string,
     *   pnlPct: ?float,
     *   coveredAssets: int,
     *   asOf: ?string
     * }
     */
    public function todayPnl(User $user): array
    {
        $rows = $this->assetDayChanges($user);
        $pnl = 0;
        $prevValue = 0;
        $asOf = null;
        foreach ($rows as $r) {
            $pnl += (int) $r['dayPnlBaseMinor'];
            $prevValue += (int) $r['previousValueBaseMinor'];
        }

        if ($rows !== []) {
            $asOf = (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        $pct = null;
        if ($prevValue !== 0) {
            $pct = $pnl / $prevValue * 100;
        }

        return [
            'baseCurrency' => $user->getBaseCurrency(),
            'pnlBaseMinor' => (string) $pnl,
            'previousValueBaseMinor' => (string) $prevValue,
            'pnlPct' => $pct,
            'coveredAssets' => count($rows),
            'asOf' => $asOf,
        ];
    }

    /**
     * Recent transactions across every account the user owns.
     *
     * @return list<array{
     *   id: string,
     *   accountId: string,
     *   accountName: string,
     *   occurredAt: string,
     *   amountMinor: string,
     *   currency: string,
     *   type: string,
     *   description: ?string,
     *   assetIsin: ?string,
     *   assetQuantity: ?string
     * }>
     */
    public function recentActivity(User $user, int $limit = 10): array
    {
        $rows = $this->conn->fetchAllAssociative(
            'SELECT t.id, t.account_id, a.name AS account_name,
                    t.occurred_at, t.amount_minor, t.currency, t.type,
                    t.description, t.asset_isin, t.asset_quantity
             FROM transactions t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE a.owner_id = :owner
             ORDER BY t.occurred_at DESC, t.id DESC
             LIMIT ' . max(1, min(100, $limit)),
            ['owner' => $user->getId()->toBinary()],
        );

        return array_map(fn(array $r) => [
            'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
            'accountId' => Uuid::fromBinary($r['account_id'])->toRfc4122(),
            'accountName' => $r['account_name'],
            'occurredAt' => $r['occurred_at'],
            'amountMinor' => (string) $r['amount_minor'],
            'currency' => $r['currency'],
            'type' => $r['type'],
            'description' => $r['description'],
            'assetIsin' => $r['asset_isin'],
            'assetQuantity' => $r['asset_quantity'],
        ], $rows);
    }
}
