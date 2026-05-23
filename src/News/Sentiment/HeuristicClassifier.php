<?php

namespace App\News\Sentiment;

use App\Entity\NewsItem;

/**
 * Keyword-lexicon fallback used when no AI key is configured, or when an AI
 * batch fails. Crude but free and instant — good enough to keep the Bullish /
 * Bearish grouping populated. Summary falls back to the source snippet.
 */
final class HeuristicClassifier implements SentimentClassifier
{
    private const BULLISH = [
        'surge', 'soar', 'soars', 'jump', 'jumps', 'rally', 'rallies', 'beat', 'beats', 'upgrade',
        'upgraded', 'gain', 'gains', 'record high', 'profit', 'growth', 'rise', 'rises', 'outperform',
        'raises', 'raised', 'tops', 'strong', 'boost', 'wins', 'approval', 'breakthrough',
    ];
    private const BEARISH = [
        'plunge', 'plunges', 'drop', 'drops', 'fall', 'falls', 'miss', 'misses', 'downgrade',
        'downgraded', 'loss', 'losses', 'cut', 'cuts', 'decline', 'declines', 'warns', 'warning',
        'slump', 'lawsuit', 'probe', 'recall', 'layoff', 'layoffs', 'weak', 'sinks', 'tumble', 'tumbles',
    ];

    public function isAvailable(): bool
    {
        return true;
    }

    public function classify(array $inputs): array
    {
        $out = [];
        foreach ($inputs as $in) {
            $hay = strtolower($in['title'] . ' ' . ($in['snippet'] ?? ''));
            $score = 0;
            foreach (self::BULLISH as $w) {
                if (str_contains($hay, $w)) {
                    $score++;
                }
            }
            foreach (self::BEARISH as $w) {
                if (str_contains($hay, $w)) {
                    $score--;
                }
            }
            $sentiment = match (true) {
                $score > 0 => NewsItem::SENTIMENT_BULLISH,
                $score < 0 => NewsItem::SENTIMENT_BEARISH,
                default => NewsItem::SENTIMENT_NEUTRAL,
            };
            $snippet = $in['snippet'];
            $summary = is_string($snippet) && trim($snippet) !== ''
                ? (mb_strlen($snippet) > 160 ? mb_substr($snippet, 0, 157) . '…' : trim($snippet))
                : null;
            $out[] = ['sentiment' => $sentiment, 'summary' => $summary];
        }
        return $out;
    }
}
