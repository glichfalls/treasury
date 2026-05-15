<?php

namespace App\Controller\Api;

use App\Entity\Asset;
use App\Entity\User;
use App\Repository\AssetRepository;
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
    ) {}

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
    public function detail(string $isin, #[CurrentUser] User $user): JsonResponse
    {
        $isin = strtoupper($isin);
        $asset = $this->assets->findByIsin($isin);
        if ($asset === null) {
            throw new NotFoundHttpException();
        }

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

        foreach ($rows as $r) {
            $type = $r['type'];
            $amount = (int) $r['amount_minor'];
            $qty = $r['asset_quantity'];
            $ccy = $r['currency'];
            $year = substr($r['occurred_at'], 0, 4);
            $accountId = Uuid::fromBinary($r['account_id'])->toRfc4122();

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
            } elseif ($type === 'dividend') {
                $totalsByCurrency[$ccy]['dividends'] = bcadd($totalsByCurrency[$ccy]['dividends'], (string) $amount, 0);
                $dividendsByYear[$ccy][$year]['amountMinor'] = bcadd(
                    $dividendsByYear[$ccy][$year]['amountMinor'] ?? '0',
                    (string) $amount,
                    0,
                );
                $dividendsByYear[$ccy][$year]['count'] = ($dividendsByYear[$ccy][$year]['count'] ?? 0) + 1;
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

        // Latest price for the asset (raw, in asset's native currency).
        $latestPrice = $this->conn->fetchAssociative(
            'SELECT price_minor, currency, occurred_at
             FROM prices
             WHERE asset_id = :id
             ORDER BY occurred_at DESC
             LIMIT 1',
            ['id' => $asset->getId()->toBinary()],
        );

        $currentValueMinor = null;
        $currentValueCurrency = null;
        if ($latestPrice !== false && $latestPrice !== null && bccomp($totalQuantity, '0', 8) !== 0) {
            // price_minor stored as price × 100 (per unit); value = qty × price × 100.
            $currentValueMinor = (string) (int) round((float) $totalQuantity * (int) $latestPrice['price_minor']);
            $currentValueCurrency = $latestPrice['currency'];
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

            'accounts' => $perAccount,
            'dividends' => $dividendsFlat,
            'transactions' => $transactions,
        ]);
    }
}
