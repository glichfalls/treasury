<?php

namespace App\News\Source;

/**
 * Outcome of a single {@see SafeUrlFetcher} request. A 304 carries no body
 * (notModified = true); otherwise body holds the response content.
 */
final class FetchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly bool $notModified,
        /** Final URL after following (validated) redirects. */
        public readonly string $finalUrl,
        public readonly ?string $etag = null,
        public readonly ?string $lastModified = null,
        public readonly ?string $contentType = null,
    ) {}

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
