<?php

namespace App\News\Sentiment;

use App\Entity\NewsItem;
use App\News\ArticleContentFetcher;
use App\Repository\NewsItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Drains the backlog of unclassified news items, assigning each a sentiment and
 * summary. Prefers the AI classifier when available, falling back to the
 * heuristic per-batch if the AI call fails so the queue always makes progress.
 */
final class NewsClassificationService
{
    public function __construct(
        private readonly OpenAiSentimentClassifier $openai,
        private readonly HeuristicClassifier $heuristic,
        private readonly ArticleContentFetcher $articleFetcher,
        private readonly NewsItemRepository $repo,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @return array{classified: int, via: string}
     */
    public function classifyPending(int $max = 200, int $batchSize = 20): array
    {
        $primary = $this->openai->isAvailable() ? $this->openai : $this->heuristic;
        $via = $primary === $this->openai ? 'openai' : 'heuristic';

        $items = $this->repo->findUnclassified(
            [NewsItem::KIND_HEADLINE, NewsItem::KIND_SOCIAL],
            $max,
        );

        $classified = 0;
        foreach (array_chunk($items, $batchSize) as $chunk) {
            $inputs = array_map(
                fn(NewsItem $n) => ['title' => $n->getTitle(), 'snippet' => $n->getSnippet()],
                $chunk,
            );

            $results = $primary->classify($inputs);
            // AI batch failed → don't lose the batch, classify it heuristically.
            if ($results === [] && $primary === $this->openai) {
                $results = $this->heuristic->classify($inputs);
            }

            foreach ($chunk as $i => $item) {
                $r = $results[$i] ?? null;
                if (!is_array($r)) {
                    continue;
                }
                $item->setSentiment($r['sentiment'] ?? NewsItem::SENTIMENT_NEUTRAL);
                if (($r['summary'] ?? null) !== null) {
                    $item->setSummary($r['summary']);
                }
                $classified++;
            }
            $this->em->flush();
        }

        return ['classified' => $classified, 'via' => $via];
    }

    /**
     * Classify a single item synchronously, called when the user opens an article
     * so we don't burn tokens on items nobody reads. For headlines this fetches
     * the article body and produces an in-depth brief; everything else (and any
     * failure) falls back to the cheap snippet-based classification.
     */
    public function classifyOne(NewsItem $item): void
    {
        // Already deep-analysed (or summarised with no body available).
        if ($item->getBrief() !== null) {
            return;
        }

        // Deep path: real article + structured brief, AI only, headlines only.
        if ($item->getKind() === NewsItem::KIND_HEADLINE && $this->openai->isAvailable()) {
            $content = $this->articleFetcher->fetch($item->getUrl());
            if ($content !== null) {
                $deep = $this->openai->deepBrief($item->getTitle(), $content);
                if ($deep !== null) {
                    $item->setSentiment($deep['sentiment']);
                    if ($deep['summary'] !== null) {
                        $item->setSummary($deep['summary']);
                    }
                    $item->setBrief($deep['brief']);
                    $this->em->flush();
                    return;
                }
            }
        }

        // Fallback: cheap one-line summary + sentiment from the snippet.
        if ($item->getSummary() !== null) {
            return;
        }
        $primary = $this->openai->isAvailable() ? $this->openai : $this->heuristic;
        $input = [['title' => $item->getTitle(), 'snippet' => $item->getSnippet()]];
        $results = $primary->classify($input);
        if ($results === [] && $primary === $this->openai) {
            $results = $this->heuristic->classify($input);
        }
        $r = $results[0] ?? null;
        if (!is_array($r)) {
            return;
        }
        $item->setSentiment($r['sentiment'] ?? NewsItem::SENTIMENT_NEUTRAL);
        if (($r['summary'] ?? null) !== null) {
            $item->setSummary($r['summary']);
        }
        $this->em->flush();
    }
}
