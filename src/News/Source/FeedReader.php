<?php

namespace App\News\Source;

use App\Entity\AssetNewsSource;
use App\Entity\NewsItem;
use App\News\NewsArticle;

/**
 * Parses an RSS or Atom document into NewsArticles. Mirrors the lightweight
 * SimpleXML approach the Google/Reddit providers already use, but covers both
 * feed dialects from one entry point and returns the channel title so a pasted
 * feed can be auto-labelled. Returns null when the body isn't a feed.
 */
final class FeedReader
{
    private const ATOM_NS = 'http://www.w3.org/2005/Atom';
    private const DC_NS = 'http://purl.org/dc/elements/1.1/';

    public function read(string $body, ?string $defaultPublisher = null, int $limit = 30): ?ParsedFeed
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }
        $xml = @simplexml_load_string($body);
        if ($xml === false) {
            return null;
        }

        $root = strtolower($xml->getName());
        return match ($root) {
            'rss', 'rdf' => $this->parseRss($xml, $defaultPublisher, $limit),
            'feed' => $this->parseAtom($xml, $defaultPublisher, $limit),
            default => null,
        };
    }

    private function parseRss(\SimpleXMLElement $xml, ?string $defaultPublisher, int $limit): ?ParsedFeed
    {
        // RSS 2.0 nests items under <channel>; RDF/RSS 1.0 puts them at the root.
        $channel = isset($xml->channel) ? $xml->channel : $xml;
        $items = isset($channel->item) ? $channel->item : $xml->item;
        if ($items === null) {
            return null;
        }
        $feedTitle = isset($channel->title) ? $this->clean((string) $channel->title) : null;

        $out = [];
        foreach ($items as $item) {
            $title = $this->clean((string) $item->title);
            $link = trim((string) $item->link);
            if ($title === '' || $link === '') {
                continue;
            }
            $publisher = $this->rssAuthor($item) ?? $defaultPublisher;
            $out[] = new NewsArticle(
                title: $title,
                url: $link,
                publishedAt: $this->parseDate((string) ($item->pubDate ?? $this->dcDate($item))),
                publisher: $publisher,
                kind: NewsItem::KIND_HEADLINE,
                snippet: $this->snippet((string) $item->description),
            );
            if (count($out) >= $limit) {
                break;
            }
        }

        return new ParsedFeed($feedTitle, $out, AssetNewsSource::TYPE_RSS);
    }

    private function parseAtom(\SimpleXMLElement $xml, ?string $defaultPublisher, int $limit): ?ParsedFeed
    {
        $atom = $xml->children(self::ATOM_NS);
        $entries = $atom->entry;
        if ($entries === null) {
            return null;
        }
        $feedTitle = isset($atom->title) ? $this->clean((string) $atom->title) : null;

        $out = [];
        foreach ($entries as $entry) {
            $e = $entry->children(self::ATOM_NS);
            $title = $this->clean((string) $e->title);
            $url = $this->atomLink($entry);
            if ($title === '' || $url === '') {
                continue;
            }
            $author = isset($e->author->name) ? $this->clean((string) $e->author->name) : null;
            $when = (string) ($e->published ?? '') ?: (string) ($e->updated ?? '');
            $body = (string) ($e->summary ?? '') ?: (string) ($e->content ?? '');
            $out[] = new NewsArticle(
                title: $title,
                url: $url,
                publishedAt: $this->parseDate($when),
                publisher: ($author !== null && $author !== '') ? $author : $defaultPublisher,
                kind: NewsItem::KIND_HEADLINE,
                snippet: $this->snippet($body),
            );
            if (count($out) >= $limit) {
                break;
            }
        }

        return new ParsedFeed($feedTitle, $out, AssetNewsSource::TYPE_ATOM);
    }

    private function atomLink(\SimpleXMLElement $entry): string
    {
        $alternate = '';
        foreach ($entry->children(self::ATOM_NS)->link as $link) {
            $href = (string) ($link->attributes()->href ?? '');
            $rel = (string) ($link->attributes()->rel ?? 'alternate');
            if ($href === '') {
                continue;
            }
            if ($rel === 'alternate') {
                return $href; // prefer the canonical article link
            }
            if ($alternate === '') {
                $alternate = $href;
            }
        }
        return $alternate;
    }

    private function rssAuthor(\SimpleXMLElement $item): ?string
    {
        $source = isset($item->source) ? $this->clean((string) $item->source) : '';
        if ($source !== '') {
            return $source;
        }
        $creator = $this->clean((string) ($item->children(self::DC_NS)->creator ?? ''));
        return $creator !== '' ? $creator : null;
    }

    private function dcDate(\SimpleXMLElement $item): string
    {
        return (string) ($item->children(self::DC_NS)->date ?? '');
    }

    private function clean(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', html_entity_decode($s, ENT_QUOTES | ENT_HTML5)));
    }

    private function snippet(string $html): ?string
    {
        if (trim($html) === '') {
            return null;
        }
        $text = $this->clean(strip_tags($html));
        if (mb_strlen($text) < 20) {
            return null;
        }
        return mb_strlen($text) > 280 ? mb_substr($text, 0, 277) . '…' : $text;
    }

    private function parseDate(string $raw): \DateTimeImmutable
    {
        if (trim($raw) !== '') {
            try {
                return new \DateTimeImmutable($raw);
            } catch (\Throwable) {
                // fall through
            }
        }
        return new \DateTimeImmutable();
    }
}
