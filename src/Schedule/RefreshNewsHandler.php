<?php

namespace App\Schedule;

use App\News\NewsFetcher;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefreshNewsHandler
{
    public function __construct(
        private readonly NewsFetcher $fetcher,
    ) {}

    public function __invoke(RefreshNewsMessage $message): void
    {
        // Classification is deliberately *not* triggered here. Items are summarized
        // on first open via NewsClassificationService::classifyOne(), so we don't
        // burn tokens on the long tail of articles nobody ever reads.
        $this->fetcher->refresh();
    }
}
