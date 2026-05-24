<?php

namespace App\News;

use App\Entity\NewsDigest;
use App\Entity\User;
use App\News\Sentiment\OpenAiSentimentClassifier;
use App\Repository\NewsDigestRepository;
use App\Settings\SettingsService;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the AI briefing: gathers the items *published* for a user's holdings
 * since their last briefing (capped at a few days) and asks the AI to
 * summarise/rank them into a report. Prod/AI — no-ops without an OpenAI key.
 *
 * The window is keyed off `published_at` (when the event happened), not
 * `created_at` (when we fetched it) — otherwise a freshly-ingested but
 * weeks-old earnings/analyst row leaks into "today's" briefing.
 */
final class DigestService
{
    /** Never look back further than this, even after a long quiet stretch. */
    private const MAX_LOOKBACK = '-3 days';


    public function __construct(
        private readonly OpenAiSentimentClassifier $openai,
        private readonly Connection $conn,
        private readonly NewsDigestRepository $digests,
        private readonly SettingsService $settings,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function latest(User $user): ?NewsDigest
    {
        return $this->digests->latestForOwner($user);
    }

    /**
     * Generate (and persist) a fresh digest for the user, or null if there's
     * nothing to summarise / no AI key.
     */
    public function generate(User $user): ?NewsDigest
    {
        if (!$this->openai->isAvailable()) {
            return null;
        }

        $held = $this->conn->fetchFirstColumn(
            'SELECT DISTINCT t.asset_isin
             FROM transactions t INNER JOIN accounts ac ON ac.id = t.account_id
             WHERE ac.owner_id = ? AND t.asset_isin IS NOT NULL',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        if ($held === []) {
            return null;
        }

        // Cover events since the last briefing so nothing is missed across a
        // weekend gap, but never more than MAX_LOOKBACK so the first run (or one
        // after a quiet stretch) doesn't dredge up old news.
        $end = new \DateTimeImmutable();
        $cap = $end->modify(self::MAX_LOOKBACK);
        $last = $this->digests->latestForOwner($user);
        $start = ($last !== null && $last->getPeriodEnd() > $cap) ? $last->getPeriodEnd() : $cap;

        // Keep AI-gated custom-source items out of the AI briefing: when the
        // global custom-AI switch is off, exclude all custom items; when on,
        // exclude only those whose source has AI turned off (a NULL link is
        // treated as allowed). Built-in sources are always included.
        $customFilter = $this->settings->isCustomNewsAiEnabled()
            ? "AND (n.source <> 'custom' OR n.news_source_id IS NULL OR ns.ai_enabled = 1)"
            : "AND n.source <> 'custom'";

        $rows = $this->conn->fetchAllAssociative(
            "SELECT n.kind, n.sentiment, n.title, n.url, n.published_at, a.ticker, a.name
             FROM news_items n
             INNER JOIN assets a ON a.id = n.asset_id
             LEFT JOIN asset_news_sources ns ON ns.id = n.news_source_id
             WHERE a.isin IN (?) AND a.news_enabled = 1 AND n.published_at >= ?
             {$customFilter}
             ORDER BY FIELD(n.kind, 'earnings', 'analyst_action', 'headline', 'social'), n.published_at DESC
             LIMIT 80",
            [$held, $start->format('Y-m-d H:i:s')],
            [ArrayParameterType::STRING, ParameterType::STRING],
        );
        if ($rows === []) {
            return null;
        }

        // One pipe-delimited line per item so the AI can cite the real date and
        // link the exact URL: KIND | TICKER | SENTIMENT | DATE | HEADLINE | URL.
        $block = '';
        foreach ($rows as $r) {
            $label = $r['ticker'] ?: ($r['name'] ?: '?');
            $kind = strtoupper(str_replace('_action', '', (string) $r['kind']));
            $sentiment = $r['sentiment'] ?: 'neutral';
            $date = substr((string) $r['published_at'], 0, 10);
            $block .= sprintf("%s | %s | %s | %s | %s | %s\n", $kind, $label, $sentiment, $date, $r['title'], $r['url']);
        }

        $content = $this->openai->summarizeDigest($block);
        if ($content === null) {
            return null;
        }

        $digest = (new NewsDigest())
            ->setOwner($user)
            ->setPeriodStart($start)
            ->setPeriodEnd($end)
            ->setContent($content)
            ->setItemCount(count($rows));
        $this->em->persist($digest);
        $this->em->flush();

        return $digest;
    }
}
