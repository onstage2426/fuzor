<?php

declare(strict_types=1);

namespace Fuzor;

class SearchResult
{
    /**
     * @param list<int>                              $ids       Document IDs in relevance order (paged window).
     * @param int                                    $hits      Total matching documents across all pages.
     * @param array<int, float>                      $scores    BM25 scores keyed by doc ID; empty for boolean search.
     * @param array<int, array<string, mixed>>|null  $documents Stored documents keyed by doc ID; null when disabled.
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $hits,
        private readonly array $scores = [],
        private readonly ?array $documents = null,
    ) {
    }

    /** BM25 score for the given doc ID, or null in boolean mode or if the ID is not in the result. */
    public function score(int $id): float|null
    {
        return $this->scores[$id] ?? null;
    }

    /** True when the document store is enabled on the index that produced this result. */
    public function hasDocuments(): bool
    {
        return $this->documents !== null;
    }

    /**
     * Stored document for the given doc ID, or null when the store is disabled or the ID is not in the result.
     *
     * @return array<string, mixed>|null
     */
    public function document(int $id): array|null
    {
        return $this->documents[$id] ?? null;
    }

    /**
     * All stored documents keyed by doc ID, or null when the store is disabled.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function documents(): array|null
    {
        return $this->documents;
    }
}
