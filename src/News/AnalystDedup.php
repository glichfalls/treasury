<?php

namespace App\News;

/**
 * Builds the cross-source de-dup key for an analyst rating change so the same
 * action reported by different providers (Yahoo, FMP, …) — each with its own
 * URL — collapses to a single news item. Keyed on the firm, the new grade and
 * the day, which together identify a rating change regardless of who reported it.
 */
final class AnalystDedup
{
    public static function key(string $firm, string $toGrade, \DateTimeImmutable $when): string
    {
        return sprintf(
            'analyst|%s|%s|%s',
            mb_strtolower(trim($firm)),
            mb_strtolower(trim($toGrade)),
            $when->format('Y-m-d'),
        );
    }
}
