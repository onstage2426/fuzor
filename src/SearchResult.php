<?php

declare(strict_types=1);

namespace Fuzor;

class SearchResult
{
    /**
     * @param list<int>         $ids    Document IDs in relevance order (paged window).
     * @param int               $hits   Total matching documents across all pages.
     * @param array<int, float> $scores BM25 scores keyed by doc ID; empty for boolean search.
     */
    public function __construct(
        public readonly array $ids,
        public readonly int $hits,
        private readonly array $scores = [],
    ) {
    }

    /** BM25 score for the given doc ID, or null in boolean mode or if the ID is not in the result. */
    public function score(int $id): float|null
    {
        return $this->scores[$id] ?? null;
    }
}
