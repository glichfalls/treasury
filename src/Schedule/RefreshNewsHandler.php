<?php

namespace App\Schedule;

use App\News\NewsFetcher;
use App\News\Sentiment\NewsClassificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshNewsHandler
{
    public function __construct(
        private readonly NewsFetcher $fetcher,
        private readonly NewsClassificationService $classifier,
    ) {}

    public function __invoke(RefreshNewsMessage $message): void
    {
        $this->fetcher->refresh();
        // Classify whatever the fetch added (and any leftover backlog).
        $this->classifier->classifyPending();
    }
}
