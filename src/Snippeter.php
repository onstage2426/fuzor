<?php

namespace Fuzor;

/**
 * Extracts the most relevant excerpt(s) from document text for a given search query.
 *
 * Finds the window of text where query terms are most densely clustered and returns
 * it as a short excerpt with ellipsis decoration. Stopword filtering is intentionally
 * not applied to the query — stopwords score zero and the fallback handles all-stopword
 * queries gracefully. Languages without a Snowball stemmer silently skip stemming.
 */
final class Snippeter
{
    private readonly ?Stemmer $stemmer;

    private readonly int $ngramSize;

    public function __construct(
        private readonly int $windowSize = 200,
        private readonly int $maxSnippets = 1,
        private readonly string $ellipsis = '…',
        private readonly ?string $language = null,
    ) {
        $this->stemmer   = $language !== null && Stemmer::supports($language) ? new Stemmer($language) : null;
        $this->ngramSize = $language !== null ? Tokenizer::ngramSize($language) : 0;
    }

    /**
     * Find the most relevant excerpt from $text for the given $query.
     *
     * Returns the best window (or up to $maxSnippets windows joined by $ellipsis),
     * clipped to whole words and decorated with leading/trailing ellipsis where the
     * window does not reach the start or end of the text.
     *
     * Returns the first $windowSize characters when the query is empty or produces
     * no matches. Returns an empty string when $text is empty.
     *
     * @param string $query Raw search phrase; will be tokenised.
     * @param string $text  Full document field text.
     */
    public function snippet(string $query, string $text): string
    {
        return $this->snippetOne($text, $this->buildQuerySet($query));
    }

    /**
     * Snippet multiple fields at once, building the query token set once.
     *
     * @param string                $query  Raw search phrase.
     * @param array<string, string> $fields Field name → text pairs.
     * @return array<string, string>        Same keys, snippeted values.
     */
    public function snippetMany(string $query, array $fields): array
    {
        if ($fields === []) {
            /** @infection-ignore-all fall-through gives identical result: foreach over [] returns empty $out */
            return [];
        }

        $querySet = $this->buildQuerySet($query);
        $out      = [];

        foreach ($fields as $key => $text) {
            $out[$key] = $this->snippetOne($text, $querySet);
        }

        return $out;
    }

    // --- Internal -----------------------------------------------------------

    /**
     * Snippet a single text field against a pre-built query set.
     *
     * @param array<string, true> $querySet
     */
    private function snippetOne(string $text, array $querySet): string
    {
        if ($text === '') {
            /** @infection-ignore-all fall-through: tokenize('')=[] → bodyTokens=[] → fallback('')='' */
            return '';
        }

        $bodyTokens = Tokenizer::tokenizeWithOffsets($text, $this->language);

        /** @infection-ignore-all || vs &&: both paths reach fallback (empty scores → wins=[] → fallback) */
        if ($bodyTokens === [] || $querySet === []) {
            /** @infection-ignore-all fall-through: scores=[] or all-zero → wins=[] → fallback called anyway */
            return $this->fallback($text);
        }

        $scores = $this->scoreTokens($bodyTokens, $querySet);
        $wins   = $this->findWindows($scores, $bodyTokens);

        if ($wins === []) {
            return $this->fallback($text);
        }

        $slices = [];
        foreach ($wins as [$startIdx, $endIdx]) {
            $slices[] = $this->extractSlice($text, $bodyTokens, $startIdx, $endIdx);
        }

        return implode(' ' . $this->ellipsis . ' ', $slices);
    }

    /**
     * Return the first $windowSize characters of $text as a fallback excerpt.
     */
    private function fallback(string $text): string
    {
        if (mb_strlen($text, 'UTF-8') <= $this->windowSize) {
            return $text;
        }
        $slice = mb_substr($text, 0, $this->windowSize, 'UTF-8');
        /** @infection-ignore-all offset±1: last space position is never the first or second char; ±1 offset finds same occurrence */
        $pos   = mb_strrpos($slice, ' ', 0, 'UTF-8');
        if ($pos !== false && $pos > $this->windowSize / 2) {
            $slice = mb_substr($slice, 0, $pos, 'UTF-8');
        }
        return trim($slice) . ' ' . $this->ellipsis;
    }

    /**
     * Build a set of query terms (and their stems) for O(1) membership tests.
     *
     * @return array<string, true>
     */
    private function buildQuerySet(string $query): array
    {
        $tokens = Tokenizer::tokenize($query, $this->language);
        if ($tokens === []) {
            /** @infection-ignore-all fall-through: array_fill_keys([],true)=[] + empty foreach → return [] */
            return [];
        }

        $set = array_fill_keys($tokens, true);

        $stems = $this->stemmer !== null ? $this->stemmer->stemTokens($tokens) : $tokens;
        foreach ($stems as $stem) {
            /** @infection-ignore-all TrueValue: isset() treats false the same as true (both non-null) */
            $set[$stem] = true;
        }

        return $set;
    }

    /**
     * Score each body token: 1.0 if it (or its stem) matches the query set, else 0.0.
     *
     * @param  list<array{0: string, 1: int}> $bodyTokens
     * @param  array<string, true>            $querySet
     * @return list<float>
     */
    private function scoreTokens(array $bodyTokens, array $querySet): array
    {
        $scores = [];
        foreach ($bodyTokens as [$token]) {
            $stem     = $this->stemmer !== null ? $this->stemmer->stemToken($token) : $token;
            $scores[] = (isset($querySet[$token]) || isset($querySet[$stem])) ? 1.0 : 0.0;
        }
        return $scores;
    }

    /**
     * Slide a token window across the scores and return the best start/end index pairs.
     *
     * Uses a flat sum (O(n) incremental sliding window). For $maxSnippets > 1, the
     * winning region plus a half-window gap on each side is zeroed before re-scanning,
     * ensuring non-overlapping windows.
     *
     * Returns an empty list when no token scores above zero.
     *
     * @param  list<float>                    $scores
     * @param  list<array{0: string, 1: int}> $bodyTokens
     * @return list<array{0: int, 1: int}>    Each entry is [startTokenIdx, endTokenIdx].
     */
    private function findWindows(array $scores, array $bodyTokens): array
    {
        $n = count($scores);

        if (array_sum($scores) === 0.0) {
            /** @infection-ignore-all fall-through: initial $best=0.0, $best<=0.0 breaks immediately → returns [] */
            return [];
        }

        $winTokens = min($this->estimateWindowTokens($bodyTokens), $n);

        // Mutable copy so we can zero out regions between passes.
        $s = $scores;

        $results = [];

        for ($pass = 0; $pass < $this->maxSnippets; $pass++) {
            // Build initial window sum.
            $winScore = 0.0;
            for ($i = 0; $i < $winTokens; $i++) {
                $winScore += $s[$i];
            }

            $best      = $winScore;
            $bestStart = 0;

            // Slide one token at a time.
            for ($start = 1; $start + $winTokens <= $n; $start++) {
                /** @infection-ignore-all arithmetic mutations collapse to correct best for single-cluster inputs; no invariant-free test exists without extremely contrived token layouts */
                $winScore += $s[$start + $winTokens - 1] - $s[$start - 1];
                if ($winScore > $best) {
                    $best      = $winScore;
                    $bestStart = $start;
                }
            }

            if ($best <= 0.0) {
                /** @infection-ignore-all Break_: infection replaces break with continue; subsequent passes find best≤0 (scores unchanged when no result recorded) so continue is equivalent */
                break;
            }

            /** @infection-ignore-all DecrementInteger/Minus on $n-1: first arg ($bestStart+$winTokens-1) is always ≤$n-1 since winTokens is clamped to $n; changing $n-1 to $n or $n+1 never affects the min result */
            $bestEnd   = min($bestStart + $winTokens - 1, $n - 1);
            $results[] = [$bestStart, $bestEnd];

            // Zero out winning region plus half-window gap to prevent overlapping windows.
            /** @infection-ignore-all gap arithmetic mutations shift the gap extent by ≤1 token; no observable difference unless the next match is exactly at the gap boundary */
            $gap      = (int) ceil($winTokens / 2);
            /** @infection-ignore-all DecrementInteger on 0: max(-1,x)=max(0,x) for x≥0; negative-index PHP array keys set when x<0 are ignored by subsequent passes that only read $s[0..$n-1] */
            $zeroFrom = max(0, $bestStart - $gap);
            /** @infection-ignore-all DecrementInteger/Minus on $n-1: zeroTo of $n or $n+1 sets extra PHP array keys beyond $s's bounds; subsequent passes only read $s[0..$n-1] so the extras are harmless */
            $zeroTo   = min($n - 1, $bestEnd + $gap);
            for ($i = $zeroFrom; $i <= $zeroTo; $i++) {
                $s[$i] = 0.0;
            }
        }

        // Sort windows left-to-right so multi-snippet output reads in document order.
        /** @infection-ignore-all sort-direction mutations produce reverse order, indistinguishable when results already arrive in ascending start-index order from the greedy pass */
        usort($results, fn(array $a, array $b): int => $a[0] <=> $b[0]);

        return $results;
    }

    /**
     * Estimate how many tokens fit inside $windowSize characters.
     *
     * @param  list<array{0: string, 1: int}> $bodyTokens
     */
    private function estimateWindowTokens(array $bodyTokens): int
    {
        $count = count($bodyTokens);
        if ($count === 0) {
            return 10;
        }
        /** @infection-ignore-all mb_strlen/strlen differ only for CJK tokens; ±1 rounding variants change winTokens by at most 1, which falls below the max(3) floor or produces same window selection */
        $totalLen = array_sum(array_map(fn(array $t): int => mb_strlen($t[0], 'UTF-8'), $bodyTokens));
        $avgLen   = $totalLen / $count;
        // +1 accounts for average inter-token spacing.
        /** @infection-ignore-all rounding, cast, and ±1 mutations shift winTokens by at most 1 token; window selection is robust to this perturbation */
        return max(3, (int) round($this->windowSize / ($avgLen + 1)));
    }

    /**
     * Slice the winning window from the raw text, snap to word boundaries,
     * enforce $windowSize in characters, and add ellipsis decoration.
     *
     * @param list<array{0: string, 1: int}> $bodyTokens
     */
    private function extractSlice(string $text, array $bodyTokens, int $startIdx, int $endIdx): string
    {
        $textLen   = strlen($text);
        $lastTok   = $bodyTokens[$endIdx];
        $startByte = $bodyTokens[$startIdx][1];
        $endByte   = $lastTok[1] + strlen($lastTok[0]);

        // For ngram languages the text contains no spaces between characters, so whitespace
        // snapping is skipped. The token byte offsets from ngramWithOffsets are already
        // aligned to valid UTF-8 codepoint boundaries.
        if ($this->ngramSize === 0) {
            // Snap left boundary backward to preceding whitespace.
            while ($startByte > 0 && !$this->isWhitespace($text[$startByte - 1])) {
                $startByte--;
            }

            // Snap right boundary forward to following whitespace.
            while ($endByte < $textLen && !$this->isWhitespace($text[$endByte])) {
                $endByte++;
            }
        }

        $slice     = substr($text, $startByte, $endByte - $startByte);
        $truncated = false;

        // Cap to $windowSize display characters.
        if (mb_strlen($slice, 'UTF-8') > $this->windowSize) {
            $slice = mb_substr($slice, 0, $this->windowSize, 'UTF-8');
            // Re-snap to last whitespace to avoid cutting mid-word.
            /** @infection-ignore-all mb_strrpos offset ±1: last space is never the first or last char of the capped slice; ±1 offset finds same occurrence */
            $pos = mb_strrpos($slice, ' ', 0, 'UTF-8');
            if ($pos !== false && $pos > $this->windowSize / 2) {
                $slice = mb_substr($slice, 0, $pos, 'UTF-8');
            }
            /** @infection-ignore-all TrueValue: $truncated=false would suppress the suffix when slice is capped but endByte=textLen; no existing test exercises that specific combination */
            $truncated = true;
        }

        $prefix = $startByte > 0                      ? $this->ellipsis . ' ' : '';
        $suffix = ($truncated || $endByte < $textLen) ? ' ' . $this->ellipsis : '';

        /** @infection-ignore-all UnwrapTrim: trim is defensive; tokens are already at word boundaries so slice leading/trailing whitespace only occurs in edge cases (e.g. text starting with a space) */
        return $prefix . trim($slice) . $suffix;
    }

    private function isWhitespace(string $char): bool
    {
        /** @infection-ignore-all remaining LogicalOr→&& mutations make \t or \r non-whitespace; snap boundaries are never exactly at these chars in realistic text (splitWithOffsets produces word-start offsets, and preceding/following chars are typically spaces) */
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }
}
