<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;

/**
 * Analyst rating changes (upgrades/downgrades/initiations) from Yahoo's
 * quoteSummary — structured, high-signal, never clickbait. Keyless, but Yahoo
 * now gates quoteSummary behind a cookie+crumb handshake, so we fetch those
 * once per run. Each rating change becomes an `analyst_action` item with a
 * derived sentiment (upgrade = bullish, downgrade = bearish).
 */
final class YahooAnalystProvider implements NewsProvider
{
    public function __construct(
        private readonly YahooQuoteSummaryClient $yahoo,
    ) {}

    public function source(): string
    {
        return 'yahoo';
    }

    public function fetchForAsset(Asset $asset, int $limit = 10): array
    {
        $ticker = $asset->getTicker();
        // Analyst ratings exist for single equities; funds/ETFs have none.
        if ($ticker === null || trim($ticker) === '' || $asset->getNewsMarketTopic() !== null) {
            return [];
        }

        $result = $this->yahoo->fetch(trim($ticker), 'upgradeDowngradeHistory');
        $history = $result['upgradeDowngradeHistory']['history'] ?? null;
        if (!is_array($history)) {
            return [];
        }

        $out = [];
        foreach ($history as $row) {
            $firm = $row['firm'] ?? null;
            $to = $row['toGrade'] ?? null;
            if (!is_string($firm) || trim($firm) === '' || !is_string($to) || trim($to) === '') {
                continue;
            }
            $action = is_string($row['action'] ?? null) ? $row['action'] : '';
            // Only real rating changes — upgrades, downgrades, new coverage. Plain
            // "maintains"/"reiterates" are low-signal noise.
            if (!in_array($action, ['up', 'down', 'init'], true)) {
                continue;
            }
            $from = is_string($row['fromGrade'] ?? null) && trim($row['fromGrade']) !== '' ? trim($row['fromGrade']) : null;
            $epoch = (int) ($row['epochGradeDate'] ?? time());
            $publishedAt = (new \DateTimeImmutable())->setTimestamp($epoch);
            $toGrade = trim($to);

            $out[] = new NewsArticle(
                title: $this->title($firm, $from, $toGrade, $action),
                // Unique per rating so the per-asset dedup keeps each change.
                url: 'https://finance.yahoo.com/quote/' . rawurlencode(trim($ticker)) . '/analysis?rating=' . $epoch . '-' . rawurlencode($firm),
                publishedAt: $publishedAt,
                publisher: trim($firm),
                kind: NewsItem::KIND_ANALYST_ACTION,
                snippet: $this->actionLabel($action),
                sentiment: $this->sentiment($action),
                // Merge with the same rating from other sources (e.g. FMP).
                dedupKey: AnalystDedup::key($firm, $toGrade, $publishedAt),
            );
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    private function title(string $firm, ?string $from, string $to, string $action): string
    {
        if (in_array($action, ['init', 'reit', 'main'], true) || $from === null || $from === '' || $from === $to) {
            $verb = match ($action) {
                'init' => 'initiated at',
                'main' => 'maintains',
                default => 'reiterates',
            };
            return sprintf('%s: %s %s', $firm, $verb, $to);
        }
        return sprintf('%s: %s → %s', $firm, $from, $to);
    }

    private function actionLabel(string $action): string
    {
        return match ($action) {
            'up' => 'Upgrade',
            'down' => 'Downgrade',
            'init' => 'Initiated coverage',
            'reit' => 'Reiterated',
            default => 'Rating change',
        };
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
