<?php

declare(strict_types=1);

namespace Fuzor;

/** Result returned by Index::inspectQuery(). */
final readonly class QueryInspection
{
    /**
     * @param list<string>     $rawTokens      Tokens before stopword filtering and stemming.
     * @param list<string>     $filteredTokens Tokens after stopword filtering and stemming.
     * @param list<QueryToken> $tokens         Per-token detail, one entry per filtered token.
     * @param list<string>     $booleanPostfix Postfix expression used by searchBoolean().
     * @param list<list<string>> $phraseGroups Stemmed token lists, one per quoted phrase in the query.
     */
    public function __construct(
        public array $rawTokens,
        public array $filteredTokens,
        public bool $stopwordsActive,
        public bool $stemmerActive,
        public bool $allStripped,
        public int $totalDocuments,
        public float $avgDocLength,
        public array $tokens,
        public array $booleanPostfix,
        /** @var list<list<string>> */
        public array $phraseGroups,
    ) {
    }
}
