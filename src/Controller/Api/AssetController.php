<?php

namespace App\Controller\Api;

use App\Entity\Asset;
use App\Entity\User;
use App\Fx\FxConverter;
use App\Price\PreMarketService;
use App\Repository\AssetRepository;
use App\TimeSeries\TimeSeriesService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

class AssetController extends AbstractController
{
    public function __construct(
        private readonly AssetRepository $assets,
        private readonly Connection $conn,
        private readonly FxConverter $fx,
        private readonly TimeSeriesService $timeSeries,
        private readonly PreMarketService $preMarket,
    ) {}

    /**
     * Profit-over-time series for a single asset, in the user's base currency.
     * Used by the asset detail view's P&L chart.
     */
    #[Route('/api/assets/{isin}/profit-series', name: 'api_asset_profit_series', methods: ['GET'])]
    public function profitSeries(string $isin, \Symfony\Component\HttpFoundation\Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $isin = strtoupper($isin);
        if ($this->assets->findByIsin($isin) === null) {
            throw new NotFoundHttpException();
        }

        $toStr = $request->query->get('to');
        $fromStr = $request->query->get('from');
        $granularity = $request->query->get('granularity', 'daily');
        if (!in_array($granularity, ['daily', 'weekly', 'monthly'], true)) {
            $granularity = 'daily';
        }
        $to = $toStr !== null ? new \DateTimeImmutable($toStr) : new \DateTimeImmutable('today');
        $from = $fromStr !== null ? new \DateTimeImmutable($fromStr) : $to->modify('-1 year');

        $points = $this->timeSeries->assetProfitSeries(
            $user,
            $isin,
            $from,
            $to,
            $granularity,
            $user->getBaseCurrency(),
        );

        return new JsonResponse([
            'isin' => $isin,
            'baseCurrency' => $user->getBaseCurrency(),
            'points' => $points,
        ]);
    }

    /**
     * Aggregate view of a single asset across all the user's accounts:
     * metadata, current quantity + value, per-account breakdown, dividends
     * by year, and the full transaction stream.
     *
     * Cross-currency aggregation is deliberately not done here — totals are
     * grouped per native currency. Adding base-currency conversion later is
     * an additive change.
     */
    #[Route('/api/assets/{isin}', name: 'api_asset_detail', methods: ['GET'])]
    public function detail(string $isin, \Symfony\Component\HttpFoundation\Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $isin = strtoupper($isin);
        $asset = $this->assets->findByIsin($isin);
        if ($asset === null) {
            throw new NotFoundHttpException();
        }

        $baseCurrency = $user->getBaseCurrency();
        $ownerBin = $user->getId()->toBinary();

        // Pull every transaction this user has for the asset.
        $rows = $this->conn->fetchAllAssociative(
            "SELECT t.id, t.occurred_at, t.amount_minor, t.currency, t.description,
                    t.type, t.source, t.category, t.asset_quantity,
                    t.account_id, ac.name AS account_name, ac.currency AS account_currency
             FROM transactions t
             INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = :owner AND t.asset_isin = :isin
             ORDER BY t.occurred_at ASC, t.id ASC",
            ['owner' => $ownerBin, 'isin' => $isin],
        );

        // Aggregate.
        $totalQuantity = '0';

        /** @var array<string, array{accountId: string, accountName: string, currency: string, quantity: string}> */
        $perAccount = [];

        /** @var array<string, array<int, array{amountMinor: string, count: int}>> by currency, then year */
        $dividendsByYear = [];

        /** Totals per native currency. */
        $totalsByCurrency = [
            // ccy => ['invested' => bigint string, 'dividends' => bigint string]
        ];

        $transactions = [];

        // Cross-currency aggregates in the user's base currency, converted via
        // historical FX (transaction date for trades/dividends, today for current
        // value). Flag if any conversion lookup was missing so the UI can warn.
        $baseInvested = 0;
        $baseDividends = 0;
        $baseFxIncomplete = false;

        foreach ($rows as $r) {
            $type = $r['type'];
            $amount = (int) $r['amount_minor'];
            $qty = $r['asset_quantity'];
            $ccy = $r['currency'];
            $year = substr($r['occurred_at'], 0, 4);
            $accountId = Uuid::fromBinary($r['account_id'])->toRfc4122();
            $txDate = new \DateTimeImmutable($r['occurred_at']);
            $baseAmount = $this->fx->convertMinor($amount, $ccy, $baseCurrency, $txDate);
            if ($baseAmount === null && $ccy !== $baseCurrency) {
                $baseFxIncomplete = true;
            }

            if (!isset($perAccount[$accountId])) {
                $perAccount[$accountId] = [
                    'accountId' => $accountId,
                    'accountName' => $r['account_name'],
                    'currency' => $r['account_currency'],
                    'quantity' => '0',
                ];
            }
            if (!isset($totalsByCurrency[$ccy])) {
                $totalsByCurrency[$ccy] = ['invested' => '0', 'dividends' => '0'];
            }

            if (in_array($type, ['trade_buy', 'trade_sell'], true) && $qty !== null) {
                $totalQuantity = bcadd($totalQuantity, $qty, 8);
                $perAccount[$accountId]['quantity'] = bcadd($perAccount[$accountId]['quantity'], $qty, 8);
                // Cash impact: buy = negative amount (money out → invested up), sell = positive (invested down).
                $totalsByCurrency[$ccy]['invested'] = bcsub($totalsByCurrency[$ccy]['invested'], (string) $amount, 0);
                if ($baseAmount !== null) {
                    $baseInvested -= $baseAmount;
                }
            } elseif ($type === 'dividend') {
                $totalsByCurrency[$ccy]['dividends'] = bcadd($totalsByCurrency[$ccy]['dividends'], (string) $amount, 0);
                $dividendsByYear[$ccy][$year]['amountMinor'] = bcadd(
                    $dividendsByYear[$ccy][$year]['amountMinor'] ?? '0',
                    (string) $amount,
                    0,
                );
                $dividendsByYear[$ccy][$year]['count'] = ($dividendsByYear[$ccy][$year]['count'] ?? 0) + 1;
                if ($baseAmount !== null) {
                    $baseDividends += $baseAmount;
                }
            }

            $transactions[] = [
                'id' => Uuid::fromBinary($r['id'])->toRfc4122(),
                'accountId' => $accountId,
                'accountName' => $r['account_name'],
                'occurredAt' => $r['occurred_at'],
                'amountMinor' => $r['amount_minor'],
                'currency' => $ccy,
                'description' => $r['description'],
                'type' => $type,
                'source' => $r['source'],
                'category' => $r['category'],
                'assetQuantity' => $qty,
            ];
        }

        // Drop accounts where the holding has been fully sold off.
        $perAccount = array_values(array_filter(
            $perAccount,
            fn($a) => bccomp($a['quantity'], '0', 8) !== 0,
        ));

        // Pre-market price (Redis-cached, only present during pre-market hours).
        $preMarketPriceMinor = null;
        $preMarketChangePct = null;
        if ($asset->getTicker() !== null && $asset->getUnitWeightGrams() === null) {
            $pmq = $this->preMarket->getQuotes([$asset->getTicker()])[$asset->getTicker()] ?? null;
            if ($pmq !== null) {
                $preMarketPriceMinor = (string) $pmq->priceMinor;
                $preMarketChangePct = $pmq->changePct;
            }
        }

        // Latest TWO prices for the asset (latest + previous) so we can show a
        // day-over-day % change next to the latest price.
        $lastTwoPrices = $this->conn->fetchAllAssociative(
            'SELECT price_minor, currency, occurred_at
             FROM prices
             WHERE asset_id = :id
             ORDER BY occurred_at DESC
             LIMIT 2',
            ['id' => $asset->getId()->toBinary()],
        );
        $latestPrice = $lastTwoPrices[0] ?? false;
        $previousPrice = $lastTwoPrices[1] ?? null;
        $dayChangePct = null;
        if ($latestPrice !== false && $previousPrice !== null) {
            $prevMinor = (float) $previousPrice['price_minor'];
            if ($prevMinor !== 0.0) {
                $dayChangePct = ((float) $latestPrice['price_minor'] - $prevMinor) / $prevMinor * 100;
            }
        }

        $currentValueMinor = null;
        $currentValueCurrency = null;
        $currentValueBaseMinor = null;
        if ($latestPrice !== false && $latestPrice !== null && bccomp($totalQuantity, '0', 8) !== 0) {
            // price_minor stored as price × 100 (per unit); value = qty × price × 100.
            $currentValueMinor = (string) (int) round((float) $totalQuantity * (int) $latestPrice['price_minor']);
            $currentValueCurrency = $latestPrice['currency'];
            // Convert current value via today's FX rate (we want present purchasing
            // power, not historical).
            $today = new \DateTimeImmutable('today');
            $converted = $this->fx->convertMinor((int) $currentValueMinor, $currentValueCurrency, $baseCurrency, $today);
            if ($converted !== null) {
                $currentValueBaseMinor = (string) $converted;
            } elseif ($currentValueCurrency !== $baseCurrency) {
                $baseFxIncomplete = true;
            }
        }

        // Reshape dividends by year so the frontend gets a flat list per currency.
        $dividendsFlat = [];
        foreach ($dividendsByYear as $ccy => $byYear) {
            $years = array_keys($byYear);
            sort($years);
            foreach ($years as $y) {
                $dividendsFlat[] = [
                    'year' => (int) $y,
                    'currency' => $ccy,
                    'amountMinor' => $byYear[$y]['amountMinor'],
                    'count' => $byYear[$y]['count'],
                ];
            }
        }

        return new JsonResponse([
            'isin' => $asset->getIsin(),
            'ticker' => $asset->getTicker(),
            'name' => $asset->getName(),
            'currency' => $asset->getCurrency(),
            'unitWeightGrams' => $asset->getUnitWeightGrams(),
            'pricePremiumPct' => $asset->getPricePremiumPct(),

            'totalQuantity' => $totalQuantity,
            'currentPriceMinor' => $latestPrice !== false ? $latestPrice['price_minor'] ?? null : null,
            'currentPriceCurrency' => $latestPrice !== false ? $latestPrice['currency'] ?? null : null,
            'currentPriceAsOf' => $latestPrice !== false ? $latestPrice['occurred_at'] ?? null : null,
            'previousPriceMinor' => $previousPrice['price_minor'] ?? null,
            'previousPriceAsOf' => $previousPrice['occurred_at'] ?? null,
            'dayChangePct' => $dayChangePct,
            'preMarketPriceMinor' => $preMarketPriceMinor,
            'preMarketChangePct' => $preMarketChangePct,
            'currentValueMinor' => $currentValueMinor,
            'currentValueCurrency' => $currentValueCurrency,

            'totalsByCurrency' => array_map(
                fn($ccy, $t) => [
                    'currency' => $ccy,
                    'investedMinor' => $t['invested'],
                    'dividendsMinor' => $t['dividends'],
                ],
                array_keys($totalsByCurrency),
                array_values($totalsByCurrency),
            ),

            // Cross-currency aggregation in the user's base currency. `baseFxIncomplete`
            // means at least one tx had no FX coverage — totals are missing pieces.
            'baseCurrency' => $baseCurrency,
            'baseInvestedMinor' => (string) $baseInvested,
            'baseDividendsMinor' => (string) $baseDividends,
            'baseCurrentValueMinor' => $currentValueBaseMinor,
            'baseFxIncomplete' => $baseFxIncomplete,

            'accounts' => $perAccount,
            'dividends' => $dividendsFlat,
            'transactions' => $this->paginateTransactions($transactions, $request),
        ]);
    }

    /**
     * Filter + sort + paginate the in-memory transaction list for the detail
     * response. Mirrors the same query-param contract as the other views
     * (q / type / from / to / sort / page / pageSize).
     *
     * @param list<array<string, mixed>> $items
     * @return array{items: list<array<string, mixed>>, total: int, page: int, pageSize: int}
     */
    private function paginateTransactions(array $items, \Symfony\Component\HttpFoundation\Request $request): array
    {
        $q = strtolower(trim((string) $request->query->get('q', '')));
        $typeFilter = (string) $request->query->get('type', '');
        $from = (string) $request->query->get('from', '');
        $to = (string) $request->query->get('to', '');

        $filtered = array_values(array_filter($items, function ($r) use ($q, $typeFilter, $from, $to) {
            if ($typeFilter !== '' && $r['type'] !== $typeFilter) return false;
            if ($from !== '' && $r['occurredAt'] < $from) return false;
            if ($to !== '' && $r['occurredAt'] > $to) return false;
            if ($q !== '') {
                $hay = strtolower(($r['description'] ?? '') . ' ' . ($r['accountName'] ?? ''));
                if (!str_contains($hay, $q)) return false;
            }
            return true;
        }));

        [$col, $dir] = array_pad(explode(':', (string) $request->query->get('sort', ''), 2), 2, '');
        $sortable = ['occurredAt', 'amount', 'type', 'description', 'quantity'];
        if (!in_array($col, $sortable, true)) $col = 'occurredAt';
        $asc = strtolower($dir) === 'asc';

        usort($filtered, function ($a, $b) use ($col, $asc) {
            $cmp = match ($col) {
                'amount' => (int) $a['amountMinor'] <=> (int) $b['amountMinor'],
                'quantity' => bccomp((string) ($a['assetQuantity'] ?? '0'), (string) ($b['assetQuantity'] ?? '0'), 8),
                'type' => strcmp((string) $a['type'], (string) $b['type']),
                'description' => strcmp((string) ($a['description'] ?? ''), (string) ($b['description'] ?? '')),
                default => strcmp((string) $a['occurredAt'], (string) $b['occurredAt']),
            };
            return $asc ? $cmp : -$cmp;
        });

        $page = max(1, (int) $request->query->get('page', '1'));
        $pageSize = max(1, min(200, (int) $request->query->get('pageSize', '25')));
        $offset = ($page - 1) * $pageSize;

        return [
            'items' => array_slice($filtered, $offset, $pageSize),
            'total' => count($filtered),
            'page' => $page,
            'pageSize' => $pageSize,
        ];
    }
}
