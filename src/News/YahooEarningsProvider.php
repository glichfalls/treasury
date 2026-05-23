<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;

/**
 * Earnings as structured `earnings` items from Yahoo quoteSummary (keyless):
 * the latest reported quarter (EPS actual vs estimate, beat/miss → sentiment)
 * and the next expected reporting date. Single equities only — funds have none.
 */
final class YahooEarningsProvider implements NewsProvider
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
        if ($ticker === null || trim($ticker) === '' || $asset->getNewsMarketTopic() !== null) {
            return [];
        }
        $result = $this->yahoo->fetch(trim($ticker), 'earningsHistory,calendarEvents');
        if ($result === null) {
            return [];
        }

        $out = [];
        $quoteUrl = 'https://finance.yahoo.com/quote/' . rawurlencode(trim($ticker)) . '/earnings';

        // Latest reported quarter (most recent history entry with an actual EPS).
        $history = $result['earningsHistory']['history'] ?? [];
        if (is_array($history)) {
            for ($i = count($history) - 1; $i >= 0; $i--) {
                $row = $history[$i];
                $actual = $row['epsActual'] ?? null;
                if (!is_array($actual) || !isset($actual['raw'])) {
                    continue;
                }
                $estimate = $row['epsEstimate'] ?? [];
                $surprise = $row['surprisePercent'] ?? [];
                $surpriseRaw = isset($surprise['raw']) ? (float) $surprise['raw'] : 0.0;
                $quarterEpoch = (int) ($row['quarter']['raw'] ?? time());
                $verb = $surpriseRaw > 0.0001 ? 'beat' : ($surpriseRaw < -0.0001 ? 'missed' : 'met');

                $out[] = new NewsArticle(
                    title: sprintf(
                        'Earnings: EPS $%s vs $%s est — %s%s',
                        $actual['fmt'] ?? $actual['raw'],
                        $estimate['fmt'] ?? ($estimate['raw'] ?? '?'),
                        $verb,
                        isset($surprise['fmt']) ? ' (' . $surprise['fmt'] . ')' : '',
                    ),
                    url: $quoteUrl . '?reported=' . $quarterEpoch,
                    publishedAt: (new \DateTimeImmutable())->setTimestamp($quarterEpoch),
                    publisher: 'Earnings',
                    kind: NewsItem::KIND_EARNINGS,
                    snippet: isset($row['quarter']['fmt']) ? 'Quarter ending ' . $row['quarter']['fmt'] : null,
                    sentiment: $surpriseRaw > 0.0001
                        ? NewsItem::SENTIMENT_BULLISH
                        : ($surpriseRaw < -0.0001 ? NewsItem::SENTIMENT_BEARISH : NewsItem::SENTIMENT_NEUTRAL),
                );
                break;
            }
        }

        // Next scheduled reporting date, if it's in the future.
        $dates = $result['calendarEvents']['earnings']['earningsDate'] ?? [];
        $next = is_array($dates) ? ($dates[0] ?? null) : null;
        if (is_array($next) && isset($next['raw'], $next['fmt']) && (int) $next['raw'] > time()) {
            $out[] = new NewsArticle(
                title: 'Upcoming earnings: ' . $next['fmt'],
                url: $quoteUrl . '?upcoming=' . (int) $next['raw'],
                publishedAt: new \DateTimeImmutable(), // discovered now; the date is in the title
                publisher: 'Earnings',
                kind: NewsItem::KIND_EARNINGS,
                snippet: 'Next scheduled reporting date',
                sentiment: NewsItem::SENTIMENT_NEUTRAL,
            );
        }

        return $out;
    }
}
