<?php

namespace App\News\Sentiment;

/**
 * Classifies news items into a sentiment and a one-line summary. Mirrors the
 * provider pattern: swap the implementation (AI vs heuristic) in one place.
 */
interface SentimentClassifier
{
    /** Whether this classifier is usable right now (e.g. an API key is set). */
    public function isAvailable(): bool;

    /**
     * Classify a batch. Output is aligned by index to the input; on a hard
     * failure (network/parse) returns an empty array so the caller can fall back.
     *
     * @param list<array{title: string, snippet: ?string}> $inputs
     * @return list<array{sentiment: string, summary: ?string}>
     */
    public function classify(array $inputs): array;
}
