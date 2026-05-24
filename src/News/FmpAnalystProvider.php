<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;
use App\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Analyst rating changes from Financial Modeling Prep's upgrades/downgrades
 * feed (free tier). Complements YahooAnalystProvider with broader firm coverage
 * and a real source link per rating; the two are merged via AnalystDedup so the
 * same change isn't stored twice. No-ops without a key. The free plan covers
 * US-listed names, so non-US tickers simply return nothing.
 */
final class FmpAnalystProvider implements NewsProvider
{
    private const URL = 'https://financialmodelingprep.com/api/v3/upgrades-downgrades';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function source(): string
    {
        return 'fmp';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $key = $this->settings->get(SettingsService::FMP_API_KEY);
        // Analyst ratings exist for single equities; funds/ETFs (which carry a
        // market topic instead of a tradeable ticker) have none.
        if ($key === null || $asset->getNewsMarketTopic() !== null) {
            return [];
        }
        $ticker = $asset->getTicker();
        if ($ticker === null || trim($ticker) === '') {
            return [];
        }
        // FMP uses bare symbols; strip an exchange suffix like ".SW"/".L".
        $symbol = strtoupper(explode('.', trim($ticker))[0]);
        if ($symbol === '') {
            return [];
        }

        try {
            $data = $this->http->request('GET', self::URL, [
                'query' => ['symbol' => $symbol, 'apikey' => $key],
                'timeout' => 10,
            ])->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('FMP analyst fetch failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
            return [];
        }
        if (!is_array($data)) {
            return [];
        }

        $out = [];
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $firm = $row['gradingCompany'] ?? null;
            $to = $row['newGrade'] ?? null;
            if (!is_string($firm) || trim($firm) === '' || !is_string($to) || trim($to) === '') {
                continue;
            }
            // Only real rating changes — upgrades, downgrades, new coverage. Plain
            // maintains/reiterates (and anything unrecognised) are low-signal noise.
            $action = $this->normalizeAction(is_string($row['action'] ?? null) ? $row['action'] : '');
            if ($action === null) {
                continue;
            }
            $from = is_string($row['previousGrade'] ?? null) && trim($row['previousGrade']) !== '' ? trim($row['previousGrade']) : null;
            try {
                $publishedAt = new \DateTimeImmutable((string) ($row['publishedDate'] ?? 'now'));
            } catch (\Exception) {
                continue;
            }
            $firm = trim($firm);
            $toGrade = trim($to);
            $newsUrl = is_string($row['newsURL'] ?? null) && trim($row['newsURL']) !== '' ? trim($row['newsURL']) : null;
            $priceTarget = $this->priceTarget($row['priceTarget'] ?? null);
            $title = $this->title($firm, $from, $toGrade, $action);

            $out[] = new NewsArticle(
                // Price target rides in the title so it shows in the feed and flows
                // into the AI briefing (which only sees the title, not the snippet).
                title: $priceTarget !== null ? $title . ' · PT ' . $priceTarget : $title,
                url: $newsUrl ?? ('https://finance.yahoo.com/quote/' . rawurlencode($symbol) . '/analysis'),
                publishedAt: $publishedAt,
                publisher: $firm,
                kind: NewsItem::KIND_ANALYST_ACTION,
                snippet: $this->actionLabel($action),
                sentiment: $this->sentiment($action),
                // Merge with the same rating from other sources (e.g. Yahoo), and
                // let this PT-bearing item win that merge.
                dedupKey: AnalystDedup::key($firm, $toGrade, $publishedAt),
                priceTarget: $priceTarget,
            );
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** FMP spells actions out ("upgrade"/"downgrade"/"initialise"…); map to up/down/init, or null to drop. */
    private function normalizeAction(string $action): ?string
    {
        $a = strtolower(trim($action));
        return match (true) {
            str_contains($a, 'upgrad') => 'up',
            str_contains($a, 'downgrad') => 'down',
            str_contains($a, 'init') => 'init',
            default => null,
        };
    }

    private function title(string $firm, ?string $from, string $to, string $action): string
    {
        if ($action === 'init' || $from === null || $from === $to) {
            return sprintf('%s: %s %s', $firm, $action === 'init' ? 'initiated at' : 'reiterates', $to);
        }
        return sprintf('%s: %s → %s', $firm, $from, $to);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'up' => 'Upgrade',
            'down' => 'Downgrade',
            default => 'Initiated coverage',
        };
    }

    /** FMP price target → "$150" / "$150.50", or null when absent or non-positive. */
    private function priceTarget(mixed $value): ?string
    {
        if (!is_numeric($value) || (float) $value <= 0) {
            return null;
        }
        return '$' . rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function sentiment(string $action): string
    {
        return match ($action) {
            'up' => NewsItem::SENTIMENT_BULLISH,
            'down' => NewsItem::SENTIMENT_BEARISH,
            default => NewsItem::SENTIMENT_NEUTRAL,
        };
    }
}
