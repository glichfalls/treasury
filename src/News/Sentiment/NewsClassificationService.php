<?php

namespace App\News\Sentiment;

use App\Entity\NewsItem;
use App\News\ArticleContentFetcher;
use App\News\CustomFeedProvider;
use App\Repository\NewsItemRepository;
use App\Settings\SettingsService;
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
        private readonly SettingsService $settings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * Whether AI may process this item. Always yes for built-in sources; for a
     * custom (user-curated) source, only when the global master switch is on AND
     * the source's own aiEnabled toggle is on. A custom item whose AI is gated
     * off behaves exactly as if no key were configured — it falls back to the
     * keyless heuristic.
     */
    private function aiAllowed(NewsItem $item): bool
    {
        if (!$this->openai->isAvailable()) {
            return false;
        }
        if ($item->getSource() !== CustomFeedProvider::SOURCE) {
            return true;
        }
        $source = $item->getNewsSource();
        return $this->settings->isCustomNewsAiEnabled()
            && ($source === null || $source->isAiEnabled());
    }

    /**
     * Whether the stored date is probably a fetch-time fallback rather than the
     * real publication date. Providers stamp "now" when the source carries no
     * date, so such items land within a few hours of when we fetched them — worth
     * a page lookup to recover the article's own date.
     */
    private function dateLooksLikeFallback(NewsItem $item): bool
    {
        return abs($item->getPublishedAt()->getTimestamp() - $item->getCreatedAt()->getTimestamp()) < 6 * 3600;
    }

    /**
     * @return array{classified: int, via: string}
     */
    public function classifyPending(int $max = 200, int $batchSize = 20): array
    {
        $primary = $this->openai->isAvailable() ? $this->openai : $this->heuristic;
        $via = $primary === $this->openai ? 'openai' : 'heuristic';

        // Custom-source items are classified lazily on open (classifyOne), where
        // the per-source AI gate applies — keep them out of the bulk drainer,
        // which uses one classifier for the whole batch and can't gate per item.
        $items = $this->repo->findUnclassified(
            [NewsItem::KIND_HEADLINE, NewsItem::KIND_SOCIAL],
            $max,
            excludeSources: [CustomFeedProvider::SOURCE],
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

        // For headlines, fetch the real article once: it carries the authoritative
        // publication date (the aggregator/listing date is often the index or
        // fetch time) and, when AI is enabled, the body for a deep brief. We pay
        // for the fetch when we'll brief it, or when the stored date looks like a
        // fetch-time fallback worth correcting even without AI.
        if ($item->getKind() === NewsItem::KIND_HEADLINE) {
            $ai = $this->aiAllowed($item);
            if ($ai || $this->dateLooksLikeFallback($item)) {
                $article = $this->articleFetcher->fetch($item->getUrl());
                if ($article !== null) {
                    if ($article->publishedAt !== null) {
                        $item->setPublishedAt($article->publishedAt);
                    }
                    if ($ai && $article->text !== null) {
                        $deep = $this->openai->deepBrief($item->getTitle(), $article->text);
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
            }
        }

        // Fallback: cheap one-line summary + sentiment from the snippet. AI when
        // allowed for this item, else the keyless heuristic. Flush first so any
        // date correction above is persisted even when a summary already exists.
        if ($item->getSummary() !== null) {
            $this->em->flush();
            return;
        }
        $primary = $this->aiAllowed($item) ? $this->openai : $this->heuristic;
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
