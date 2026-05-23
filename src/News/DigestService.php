<?php

namespace App\News;

use App\Entity\NewsDigest;
use App\Entity\User;
use App\News\Sentiment\OpenAiSentimentClassifier;
use App\Repository\NewsDigestRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Builds the 24h AI briefing: gathers the items loaded for a user's holdings in
 * the last day and asks the AI to summarise/rank them into a report. Prod/AI —
 * no-ops without an OpenAI key.
 */
final class DigestService
{
    public function __construct(
        private readonly OpenAiSentimentClassifier $openai,
        private readonly Connection $conn,
        private readonly NewsDigestRepository $digests,
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

        $end = new \DateTimeImmutable();
        $start = $end->modify('-24 hours');

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

        $rows = $this->conn->fetchAllAssociative(
            "SELECT n.kind, n.sentiment, n.title, a.ticker, a.name
             FROM news_items n INNER JOIN assets a ON a.id = n.asset_id
             WHERE a.isin IN (?) AND a.news_enabled = 1 AND n.created_at >= ?
             ORDER BY FIELD(n.kind, 'earnings', 'analyst_action', 'headline', 'social'), n.published_at DESC
             LIMIT 80",
            [$held, $start->format('Y-m-d H:i:s')],
            [ArrayParameterType::STRING, ParameterType::STRING],
        );
        if ($rows === []) {
            return null;
        }

        $block = '';
        foreach ($rows as $r) {
            $label = $r['ticker'] ?: ($r['name'] ?: '?');
            $kind = strtoupper(str_replace('_action', '', (string) $r['kind']));
            $sentiment = $r['sentiment'] !== null ? " ({$r['sentiment']})" : '';
            $block .= "- [{$kind}] {$label}{$sentiment}: {$r['title']}\n";
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
