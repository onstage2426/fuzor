<?php

namespace Fuzor;

/**
 * Extracts the most relevant excerpt(s) from document text for a given search query.
 *
 * Finds the window of text where query terms are most densely clustered and returns
 * it as a short excerpt with ellipsis decoration. Optionally accepts a BCP 47 language
 * tag so that stemmed query terms match their surface forms in the text. Should match
 * the language used at index creation time. Languages without a Snowball stemmer
 * silently skip stemming.
 *
 * Stopword filtering is intentionally not applied to the query — stopwords score
 * zero and the fallback handles all-stopword queries gracefully. Pre-filter the
 * query before passing it in if you need stopword-aware snippeting.
 *
 * Typical usage after a search:
 *
 *   $snip = new Snippeter(language: 'en');
 *   echo $snip->snippet('fast connections', $doc['body']);
 *
 *   // Multiple fields at once:
 *   ['title' => $t, 'body' => $b] = $snip->snippetMany('fast connections', [
 *       'title' => $doc['title'],
 *       'body'  => $doc['body'],
 *   ]);
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
            return '';
        }

        $bodyTokens = Tokenizer::tokenizeWithOffsets($text, $this->language);

        if ($bodyTokens === [] || $querySet === []) {
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
            return [];
        }

        $set = array_fill_keys($tokens, true);

        $stems = $this->stemmer !== null ? $this->stemmer->stemTokens($tokens) : $tokens;
        foreach ($stems as $stem) {
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
                $winScore += $s[$start + $winTokens - 1] - $s[$start - 1];
                if ($winScore > $best) {
                    $best      = $winScore;
                    $bestStart = $start;
                }
            }

            if ($best <= 0.0) {
                break;
            }

            $bestEnd   = min($bestStart + $winTokens - 1, $n - 1);
            $results[] = [$bestStart, $bestEnd];

            // Zero out winning region plus half-window gap to prevent overlapping windows.
            $gap      = (int) ceil($winTokens / 2);
            $zeroFrom = max(0, $bestStart - $gap);
            $zeroTo   = min($n - 1, $bestEnd + $gap);
            for ($i = $zeroFrom; $i <= $zeroTo; $i++) {
                $s[$i] = 0.0;
            }
        }

        // Sort windows left-to-right so multi-snippet output reads in document order.
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
        $totalLen = array_sum(array_map(fn(array $t): int => mb_strlen($t[0], 'UTF-8'), $bodyTokens));
        $avgLen   = $totalLen / $count;
        // +1 accounts for average inter-token spacing.
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
            $pos = mb_strrpos($slice, ' ', 0, 'UTF-8');
            if ($pos !== false && $pos > $this->windowSize / 2) {
                $slice = mb_substr($slice, 0, $pos, 'UTF-8');
            }
            $truncated = true;
        }

        $prefix = $startByte > 0                      ? $this->ellipsis . ' ' : '';
        $suffix = ($truncated || $endByte < $textLen) ? ' ' . $this->ellipsis : '';

        return $prefix . trim($slice) . $suffix;
    }

    private function isWhitespace(string $char): bool
    {
        return $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r";
    }
}
