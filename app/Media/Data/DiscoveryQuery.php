<?php

namespace App\Media\Data;

use App\Models\VideoCategory;

final readonly class DiscoveryQuery
{
    public function __construct(
        public string $kind,
        public string $term,
        public ?int $maxDurationMs = null,
        public ?string $pageToken = null,
        public ?string $regionCode = null,
        public ?string $categorySlug = null,
    ) {}

    public static function search(string $query, ?int $maxDurationMs, ?string $pageToken): self
    {
        return new self(
            kind: 'search',
            term: $query,
            maxDurationMs: $maxDurationMs,
            pageToken: $pageToken,
        );
    }

    public static function category(VideoCategory $category, ?int $maxDurationMs, ?string $pageToken): self
    {
        return new self(
            kind: 'category',
            term: $category->query_template,
            maxDurationMs: $maxDurationMs,
            pageToken: $pageToken,
            regionCode: $category->region_code,
            categorySlug: $category->slug,
        );
    }

    /**
     * The upstream query, stripped of anything that does not change the cached
     * result set. max_duration_ms is deliberately absent: we cache a superset
     * and filter in PHP, so one page serves every duration variant across every
     * tenant. Keying on it would fragment the cache and multiply 100-unit calls.
     */
    public function cacheKey(): string
    {
        $normalised = $this->normalisedTerm();

        $parts = [
            // Bumped whenever the cached card shape changes, so a payload
            // written by an older build is never rehydrated into the new one.
            'v2',
            $this->kind === 'category' ? 'category' : 'search',
            $this->categorySlug ?? sha1($normalised),
            $this->regionCode ?? '-',
            $this->pageToken ?? '-',
        ];

        return 'discover:'.implode(':', $parts);
    }

    /**
     * Word order is meaningful to YouTube's ranking, so it is preserved. Case,
     * surrounding whitespace and repeated spaces are not.
     */
    public function normalisedTerm(): string
    {
        $term = mb_strtolower(trim($this->term));

        return (string) preg_replace('/\s+/u', ' ', $term);
    }
}
