<?php

namespace App\News;

use App\Entity\Asset;
use App\Entity\NewsItem;

/**
 * Drops low-value items before they're stored: listicle/clickbait headlines and
 * the publishers that churn them out, 13F-filing spam, and Reddit's recurring
 * discussion/megathreads. Keyless and deterministic — the AI relevance pass (in
 * prod) refines further, but this keeps the feed readable without a key.
 */
final class NewsQualityFilter
{
    /** Publishers dropped wholesale: 13F-filing mills + clickbait/listicle mills. */
    private const BLOCKED_PUBLISHERS = [
        // 13F-filing content mills
        'marketbeat', 'defense world', 'etf daily news', 'american banking news',
        'modern readers', 'cerbat gem', 'zolmax', 'ticker report', 'tickerreport',
        'the markets daily', 'dakota financial news', 'transcript daily', 'mayfield recorder',
        // clickbait / listicle mills
        'the motley fool', 'motley fool', 'investorplace', '24/7 wall st', '247 wall st',
        'gobankingrates', 'insider monkey',
    ];

    /** Boilerplate headlines from 13F-filing aggregators. */
    private const FILING_PATTERNS = [
        '/\b(purchases|sells|buys|acquires|boosts|trims|lowers|raises|cuts|reduces|increases|grows|takes)\b.{0,30}\b(shares|stock position|holdings|stake|position)\b/i',
        '/\bshares?\b.{0,30}\b(purchased|sold|acquired|bought)\s+by\b/i',
        '/\bhas\s+\$[\d.]+\s+(million|billion)\b.{0,20}\b(stock\s+)?(position|holdings|stake)\b/i',
        '/\b(stock\s+)?(position|holdings|stake)\b.{0,30}\b(boosted|lowered|trimmed|raised|reduced|increased|decreased|cut)\s+by\b/i',
        '/\b13F\b/i',
    ];

    /** Listicle / clickbait headline shapes. */
    private const CLICKBAIT_PATTERNS = [
        '/^\s*\d+\s+(stocks?|reasons|things|ways|dividend|growth|ai|top|best|magnificent|no[- ]brainer)\b/i',
        '/\b\d+\s+(top|best|growth|dividend|ai|magnificent|high[- ]yield|no[- ]brainer)\s+stocks?\b/i',
        '/\bstocks?\s+to\s+(buy|watch|avoid|sell|consider|own)\b/i',
        '/\bbetter\s+buy\b/i',
        '/\b(should|could|would)\s+you\s+buy\b/i',
        '/\bis\s+.{1,40}\s+a\s+(buy|sell|good\s+stock|millionaire[- ]maker)\b/i',
        '/\b(millionaire|get\s+rich|turn\s+\$[\d,]+\s+into)\b/i',
        '/\bmotley\s+fool\b/i',
        "/\\bwe\\s+(like|don'?t\\s+like|find\\s+interesting|are\\s+watching)\\b/i",
        '/\b(buy|sell|hold)\s+now\?/i',
        '/share price\s*\|/i', // fund directory / quote-page listings, not news
    ];

    /** Reddit recurring threads that aren't real news. */
    private const SOCIAL_NOISE_PATTERNS = [
        '/\b(daily|weekly|weekend|monthly)\s+(discussion|thread|general\s+discussion)\b/i',
        '/\bdiscussion\s+thread\b/i',
        '/what\s+are\s+your\s+moves\b/i',
        '/\b(rate|roast)\s+my\s+portfolio\b/i',
        '/\bmoves\s+tomorrow\b/i',
        '/\bmegathread\b/i',
    ];

    /** Corporate/fund suffixes and generic market words that make poor anchors. */
    private const ANCHOR_STOPWORDS = [
        'inc', 'corp', 'corporation', 'ltd', 'limited', 'plc', 'company', 'holdings', 'holding',
        'group', 'the', 'etf', 'ucits', 'fund', 'trust', 'index', 'acc', 'dist', 'class',
        'world', 'global', 'core', 'total', 'all', 'growth', 'value', 'equity', 'equities',
        'income', 'market', 'markets', 'developed', 'emerging', 'large', 'small', 'mid', 'cap',
        'plus', 'select', 'sector', 'shares', 'stock', 'fund', 'usd', 'eur', 'chf', 'hedged',
    ];

    /**
     * Is the article actually about this holding? Drops loose keyword matches
     * (e.g. a Premier League article surfacing for a broad ETF, or generic
     * "investing in ETFs" chatter). Matches the ticker or a distinctive token of
     * the name / market topic. Callers decide where to apply it (headlines and
     * broad-subreddit social, but not a holding's dedicated subreddit).
     */
    public function matchesAsset(NewsArticle $article, Asset $asset): bool
    {
        $anchors = $this->anchors($asset);
        if ($anchors === []) {
            return true; // nothing distinctive to match on — don't over-filter
        }
        $hay = strtolower($article->title . ' ' . ($article->snippet ?? ''));
        foreach ($anchors as $anchor) {
            if (preg_match('/\b' . preg_quote($anchor, '/') . '/i', $hay) === 1) {
                return true;
            }
        }
        return false;
    }

    /** @return string[] Lowercased distinctive terms identifying the asset. */
    private function anchors(Asset $asset): array
    {
        $terms = [];

        $ticker = $asset->getTicker();
        if ($ticker !== null && trim($ticker) !== '') {
            $base = strtolower(explode('.', trim($ticker))[0]);
            if (strlen($base) >= 2) {
                $terms[] = $base;
            }
        }

        // Prefer the market topic (ETFs) over the fund name as the anchor source.
        $source = $asset->getNewsMarketTopic() ?? $asset->getName();
        if ($source !== null) {
            foreach (preg_split('/[^a-z0-9&]+/i', strtolower($source)) ?: [] as $tok) {
                if (strlen($tok) >= 3 && !in_array($tok, self::ANCHOR_STOPWORDS, true)) {
                    $terms[] = $tok;
                }
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param bool $trustedSource When true (a user-curated custom source), skip
     *   the publisher blocklist — the user picked this outlet deliberately — but
     *   still drop clickbait/filing-spam headline shapes.
     */
    public function accepts(NewsArticle $article, bool $trustedSource = false): bool
    {
        if ($article->kind === NewsItem::KIND_SOCIAL) {
            return !$this->matchesAny($article->title, self::SOCIAL_NOISE_PATTERNS);
        }

        if (!$trustedSource && $article->publisher !== null && $this->isBlockedPublisher($article->publisher)) {
            return false;
        }
        return !$this->matchesAny($article->title, self::FILING_PATTERNS)
            && !$this->matchesAny($article->title, self::CLICKBAIT_PATTERNS);
    }

    private function isBlockedPublisher(string $publisher): bool
    {
        $p = strtolower($publisher);
        foreach (self::BLOCKED_PUBLISHERS as $blocked) {
            if (str_contains($p, $blocked)) {
                return true;
            }
        }
        return false;
    }

    /** @param string[] $patterns */
    private function matchesAny(string $title, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return true;
            }
        }
        return false;
    }
}
