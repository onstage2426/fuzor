<?php

declare(strict_types=1);

namespace Fuzor;

/** Per-token breakdown returned by Index::inspectQuery(). */
final readonly class QueryToken
{
    /**
     * @param list<array{term: string, numHits: int, numDocs: int, distance: int|null}> $wordlistRows
     */
    public function __construct(
        /** Original input word before stopword filtering and stemming. */
        public string $raw,
        /** Token after stopword filtering and stemming. */
        public string $processed,
        /** True when this is the last token and asYouType prefix matching is active. */
        public bool $isLast,
        /** Whether the token matched anything in the wordlist. */
        public bool $found,
        /** How the token matched: 'exact', 'prefix', 'fuzzy', or 'none'. */
        public string $matchType,
        /** Matching wordlist entries. */
        public array $wordlistRows,
        /** Total hit count across all matching wordlist entries. */
        public int $numHits,
        /** Total document count across all matching wordlist entries. */
        public int $numDocs,
    ) {
    }
}
