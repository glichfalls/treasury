<?php

namespace App\News;

use App\Entity\Asset;
use App\News\Sentiment\OpenAiSentimentClassifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fills in the market topic for fund/ETF holdings that don't have one, so news
 * is searched against the index/market they track rather than the (generic)
 * fund name. Runs once per asset — the inferred topic persists. AI-backed, so
 * it no-ops without an OpenAI key (dev falls back to name + relevance gate).
 */
final class EtfTopicInferer
{
    public function __construct(
        private readonly OpenAiSentimentClassifier $openai,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    /**
     * @param Asset[] $assets
     * @return int number of topics inferred
     */
    public function enrich(array $assets): int
    {
        if (!$this->openai->isAvailable()) {
            return 0;
        }

        $inferred = 0;
        foreach ($assets as $asset) {
            if ($asset->getNewsMarketTopic() !== null || !$this->looksLikeFund($asset->getName())) {
                continue;
            }
            $topic = $this->openai->inferMarketTopic($asset->getName() ?? '', $asset->getIsin());
            if ($topic !== null) {
                $asset->setNewsMarketTopic($topic);
                $inferred++;
            }
        }
        if ($inferred > 0) {
            $this->em->flush();
        }
        return $inferred;
    }

    private function looksLikeFund(?string $name): bool
    {
        return $name !== null && preg_match(
            '/\b(ETF|ETP|UCITS|Fund|Index|Trust|SICAV|MSCI|FTSE|S&P|Bond|Bonds|iShares|Vanguard|Amundi|Xtrackers|SPDR|Invesco|Lyxor|WisdomTree)\b/i',
            $name,
        ) === 1;
    }
}
