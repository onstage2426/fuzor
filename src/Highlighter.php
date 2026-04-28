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
    private readonly int $ngramSize;

    public function __construct(
        private readonly string $open = '<mark>',
        private readonly string $close = '</mark>',
        private readonly bool $asYouType = true,
        private readonly ?string $language = null,
    ) {
        $this->ngramSize = $language !== null ? Tokenizer::ngramSize($language) : 0;
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
        $pattern = $this->buildPattern(Tokenizer::tokenize($phrase, $this->language, false));
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
        $pattern = $this->buildPattern(Tokenizer::tokenize($phrase, $this->language, false));
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

        // Separate tokens by script type so each group gets the right boundary style.
        $ngramAlts = [];
        $asciiAlts = [];

        foreach ($tokens as $token) {
            /** @infection-ignore-all tokenizer strips all regex special chars from tokens; preg_quote is defensive but untestable via public API */
            $quoted = preg_quote($token, '/');
            if (Tokenizer::isNgramToken($token)) {
                $ngramAlts[] = $quoted;
            } else {
                $asciiAlts[] = $quoted;
            }
        }

        // For non-ngram queries, apply asYouType prefix extension to the last token.
        if ($this->ngramSize === 0 && $this->asYouType && $asciiAlts !== []) {
            $lastIdx = count($asciiAlts) - 1;
            $asciiAlts[$lastIdx] .= '[\p{L}\p{N}\p{Pc}]*';
            /** @infection-ignore-all word-boundary lookahead makes alternation order irrelevant; moving prefix first is style only */
            array_unshift($asciiAlts, array_splice($asciiAlts, $lastIdx, 1)[0]);
        }

        $parts = [];

        // CJK/Thai tokens: plain substring match — word boundaries would block all matches
        // because every character in these scripts is \p{L}.
        if ($ngramAlts !== []) {
            /** @infection-ignore-all non-capturing group wrapper has no effect on match results; alternation works identically without it */
            $parts[] = '(?:' . implode('|', $ngramAlts) . ')';
        }

        // ASCII/Latin tokens: Unicode-aware word boundaries prevent partial-word highlights.
        if ($asciiAlts !== []) {
            $parts[] = '(?<![\p{L}\p{N}\p{Pc}])(?:' . implode('|', $asciiAlts) . ')(?![\p{L}\p{N}\p{Pc}])';
        }

        return '/' . implode('|', $parts) . '/iu';
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
