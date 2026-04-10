<?php

namespace Fuzor;

/**
 * Highlights search terms inside document text.
 *
 * Typical usage after a search:
 *
 *   $hl = new Highlighter();
 *   echo $hl->highlight('fast sedan', $doc['title']);
 *
 *   // or multiple fields at once:
 *   [$title, $body] = array_values(
 *       $hl->highlightMany('fast sedan', ['title' => $doc['title'], 'body' => $doc['body']])
 *   );
 *
 * Matching follows the same tokenisation as the index. When $asYouType is true
 * (the default) the last token is matched as a word prefix — "merc" highlights
 * the full word "Mercedes". Matching is Unicode-aware and case-insensitive.
 * Stemming is intentionally not applied: the user's raw query terms are highlighted.
 */
final class Highlighter
{
    public function __construct(
        private readonly string $open = '<mark>',
        private readonly string $close = '</mark>',
        private readonly bool $asYouType = true,
    ) {
    }

    /**
     * Highlight search terms from $phrase inside $text.
     *
     * @param  string $phrase Raw search phrase (will be tokenised).
     * @param  string $text   Text to highlight.
     * @return string         $text with matched terms wrapped in open/close tags.
     */
    public function highlight(string $phrase, string $text): string
    {
        $pattern = $this->buildPattern(Tokenizer::tokenize($phrase));
        if ($pattern === null) {
            return $text;
        }
        return $this->apply($pattern, $text);
    }

    /**
     * Highlight search terms in multiple fields at once.
     *
     * Builds the regex pattern once and applies it to every field, so this is
     * more efficient than calling highlight() in a loop.
     *
     * @param  string                $phrase Raw search phrase (will be tokenised).
     * @param  array<string, string> $fields Field name → text pairs.
     * @return array<string, string>         Same keys, with highlights applied.
     */
    public function highlightMany(string $phrase, array $fields): array
    {
        $pattern = $this->buildPattern(Tokenizer::tokenize($phrase));
        if ($pattern === null) {
            return $fields;
        }
        return array_map(fn(string $text): string => $this->apply($pattern, $text), $fields);
    }

    /**
     * Build a compiled regex from a token list.
     *
     * The last token becomes a prefix alternative (word-start anchor + [\p{L}\p{N}\p{Pc}]*)
     * when $asYouType is true. Prefix alternative is placed first in the alternation so it
     * takes precedence over any shorter exact token that shares the same word start.
     *
     * Returns null when the token list is empty.
     *
     * @param  list<string> $tokens
     */
    private function buildPattern(array $tokens): ?string
    {
        if ($tokens === []) {
            return null;
        }

        $alts    = array_map(fn(string $t): string => preg_quote($t, '/'), $tokens);
        $lastIdx = count($alts) - 1;

        if ($this->asYouType) {
            // Extend the last token to match the rest of the word it prefixes.
            $alts[$lastIdx] .= '[\p{L}\p{N}\p{Pc}]*';
            // Move it to the front so it wins over shorter exact matches at the same position.
            array_unshift($alts, array_splice($alts, $lastIdx, 1)[0]);
        }

        $group = '(?:' . implode('|', $alts) . ')';

        // Unicode-aware word boundaries: assert the match is not adjacent to another
        // word character on either side. [\p{L}\p{N}\p{Pc}] is one character → fixed-
        // length lookbehind/lookahead, compatible with PCRE.
        return '/(?<![\p{L}\p{N}\p{Pc}])' . $group . '(?![\p{L}\p{N}\p{Pc}])/iu';
    }

    private function apply(string $pattern, string $text): string
    {
        return preg_replace_callback(
            $pattern,
            fn(array $m): string => $this->open . $m[0] . $this->close,
            $text
        ) ?? $text;
    }
}
