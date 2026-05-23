<?php

namespace App\News\Sentiment;

use App\Entity\NewsItem;
use App\Settings\SettingsService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Sentiment + summary via OpenAI gpt-4o-mini. One batched chat completion per
 * call (the instruction prompt is amortised across many headlines), JSON mode
 * for parseable output. Cost is a fraction of a cent per batch. Key comes from
 * admin settings, not .env.
 */
final class OpenAiSentimentClassifier implements SentimentClassifier
{
    private const URL = 'https://api.openai.com/v1/chat/completions';
    private const MODEL = 'gpt-4o-mini';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function isAvailable(): bool
    {
        return $this->settings->has(SettingsService::OPENAI_API_KEY);
    }

    public function classify(array $inputs): array
    {
        $key = $this->settings->get(SettingsService::OPENAI_API_KEY);
        if ($key === null || $inputs === []) {
            return [];
        }

        $lines = [];
        foreach ($inputs as $i => $in) {
            $text = $in['title'];
            if (!empty($in['snippet'])) {
                $text .= ' — ' . $in['snippet'];
            }
            $lines[] = ($i + 1) . '. ' . str_replace(["\r", "\n"], ' ', $text);
        }

        $system = 'You are a financial news classifier. For each numbered item, judge whether it is '
            . 'bullish, bearish, or neutral for the asset it concerns, and write a concise one-sentence '
            . 'summary (max 25 words). Respond ONLY with JSON of the form '
            . '{"items":[{"sentiment":"bullish|bearish|neutral","summary":"..."}]} with exactly one entry '
            . 'per input, in the same order.';

        try {
            $res = $this->http->request('POST', self::URL, [
                'headers' => ['Authorization' => 'Bearer ' . $key],
                'json' => [
                    'model' => self::MODEL,
                    'temperature' => 0,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => implode("\n", $lines)],
                    ],
                ],
                'timeout' => 30,
            ]);
            $data = $res->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->warning('OpenAI classify failed', ['error' => $e->getMessage()]);
            return [];
        }

        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            return [];
        }
        $parsed = json_decode($content, true);
        $items = is_array($parsed) ? ($parsed['items'] ?? null) : null;
        if (!is_array($items)) {
            return [];
        }

        $out = [];
        foreach (array_keys($inputs) as $i) {
            $entry = $items[$i] ?? null;
            $sentiment = is_array($entry) ? ($entry['sentiment'] ?? null) : null;
            $summary = is_array($entry) ? ($entry['summary'] ?? null) : null;
            $out[] = [
                'sentiment' => $this->normalize($sentiment),
                'summary' => is_string($summary) && trim($summary) !== '' ? trim($summary) : null,
            ];
        }
        return $out;
    }

    private function normalize(mixed $sentiment): string
    {
        return match (is_string($sentiment) ? strtolower(trim($sentiment)) : '') {
            'bullish' => NewsItem::SENTIMENT_BULLISH,
            'bearish' => NewsItem::SENTIMENT_BEARISH,
            default => NewsItem::SENTIMENT_NEUTRAL,
        };
    }
}
